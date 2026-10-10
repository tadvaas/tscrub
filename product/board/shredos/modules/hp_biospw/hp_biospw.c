// SPDX-License-Identifier: GPL-2.0
/*
 * hp_biospw — send one HP WMI BIOS-setting request from userspace.
 *
 * This exists because tScrub must clear an HP BIOS *Setup Password* on machines
 * whose firmware exposes no password object to hp-bioscfg at all: there is no
 * sysfs attribute to write, and the driver's own request frame is malformed
 * (see research/bios-unlock/16-… and 18-…). The frame that does work is a plain
 * call to the firmware's SetBiosSetting method, which Windows itself uses.
 *
 * Deliberately narrow, so this cannot become a general BIOS-write primitive:
 *
 *   - the WMI GUID, instance and method id are hardcoded (method 1 =
 *     SetBiosSetting); there is no way to call any other method or device;
 *   - the only interface is one proc file, mode 0600 (root only);
 *   - input must be 2 or 3 NUL-separated fields — name, value, and optionally
 *     the credential — which are encoded into the documented length-prefixed
 *     UTF-16 element frame by hp_biospw_frame.h (byte-exact unit-testable);
 *   - field sizes and the total frame are bounded;
 *   - the value and the credential are NEVER logged. Only the setting name and
 *     the resulting status reach the kernel log.
 *
 * Usage (root):
 *   insmod hp_biospw.ko
 *   printf '%s\0%s\0%s' "Setup Password" "<utf-16/>" "<utf-16/>CURRENT" \
 *       > /proc/hp_biospw     # 3 fields; omit the trailing \0 field for 2
 *   cat /proc/hp_biospw       # "status 0x00" / "status 0x06" / "acpi 0x…" / …
 *   rmmod hp_biospw
 *
 * Status codes from the BIOS (as measured, see doc 18): 0x00 the firmware
 * processed the request, 0x04 unknown setting / malformed, 0x05 invalid value
 * for that setting, 0x06 refused (authentication or parameter). NOTE 0x00 does
 * not by itself prove that a value was stored — callers must corroborate.
 */

#include <linux/module.h>
#include <linux/kernel.h>
#include <linux/stdarg.h>
#include <linux/fs.h>
#include <linux/proc_fs.h>
#include <linux/uaccess.h>
#include <linux/slab.h>
#include <linux/acpi.h>
#include <linux/wmi.h>

#include "hp_biospw_frame.h"

/* HPWMI_BS — the "BIOS settings" WMI block (object id "BS"). */
#define HPBIOSPW_GUID		"1F4C91EB-DC5C-460b-951D-C7CB9B4B8D5E"
#define HPBIOSPW_INSTANCE	0
#define HPBIOSPW_METHOD_ID	1	/* SetBiosSetting */
#define HPBIOSPW_MAX_INPUT	4096	/* bytes of userspace input */

#define HPBIOSPW_RESULT_LEN	64

static char hpbiospw_result[HPBIOSPW_RESULT_LEN] = "none\n";
static size_t hpbiospw_result_len = sizeof("none\n") - 1;

static void hpbiospw_set_result(const char *fmt, ...)
{
	va_list args;

	va_start(args, fmt);
	hpbiospw_result_len =
		vscnprintf(hpbiospw_result, sizeof(hpbiospw_result), fmt, args);
	va_end(args);
}

static ssize_t hpbiospw_write(struct file *file, const char __user *ubuf,
			      size_t count, loff_t *ppos)
{
	const char *fields[HPBIOSPW_MAX_FIELDS];
	unsigned int nfields = 0;
	struct acpi_buffer input, output = { ACPI_ALLOCATE_BUFFER, NULL };
	union acpi_object *obj;
	unsigned char *frame;
	char *kbuf, *tail;
	acpi_status status;
	size_t i, flen;
	int rc = count;

	if (count == 0 || count > HPBIOSPW_MAX_INPUT)
		return -EINVAL;

	/* One spare byte so the last field is always NUL-terminated even when
	 * the caller ends the write with a field separator. */
	kbuf = kmalloc(count + 1, GFP_KERNEL);
	if (!kbuf)
		return -ENOMEM;
	if (copy_from_user(kbuf, ubuf, count)) {
		kfree(kbuf);
		return -EFAULT;
	}
	kbuf[count] = '\0';

	tail = kbuf + count;
	if (count && kbuf[count - 1] == '\n') {	/* tolerate one trailing newline */
		kbuf[count - 1] = '\0';
		tail--;
	}

	/* NUL-separated fields; a trailing separator means an empty last field
	 * (which is meaningful — an empty value or an empty credential encodes
	 * as the four bytes 02 00 00 00, not as a zero-length element). */
	fields[nfields++] = kbuf;
	for (i = 0; i < (size_t)(tail - kbuf); i++) {
		if (kbuf[i] != '\0')
			continue;
		if (nfields >= HPBIOSPW_MAX_FIELDS) {
			hpbiospw_set_result("badfield\n");
			goto out;
		}
		fields[nfields++] = kbuf + i + 1;
	}
	if (nfields < 2) {
		hpbiospw_set_result("badfield\n");
		goto out;
	}

	frame = kmalloc(HPBIOSPW_MAX_FRAME, GFP_KERNEL);
	if (!frame) {
		rc = -ENOMEM;
		goto out;
	}

	flen = hpbiospw_frame(frame, HPBIOSPW_MAX_FRAME, fields, nfields);
	if (flen == 0) {
		hpbiospw_set_result("badframe\n");
		kfree(frame);
		goto out;
	}

	input.length = flen;
	input.pointer = frame;

	status = wmi_evaluate_method(HPBIOSPW_GUID, HPBIOSPW_INSTANCE,
				     HPBIOSPW_METHOD_ID, &input, &output);
	if (ACPI_FAILURE(status)) {
		hpbiospw_set_result("acpi 0x%x\n", (unsigned int)status);
	} else if (!output.pointer) {
		hpbiospw_set_result("noresult\n");
	} else {
		obj = output.pointer;
		if (obj->type != ACPI_TYPE_INTEGER) {
			hpbiospw_set_result("badtype %d\n", obj->type);
		} else {
			u64 raw = obj->integer.value;

			hpbiospw_set_result("status 0x%02llx\n", raw);
			/* Name and status only — never the value or credential. */
			pr_info("hp_biospw: %s -> 0x%02llx\n", fields[0], raw);
		}
	}

	kfree(output.pointer);
	kfree(frame);
out:
	kfree(kbuf);
	return rc;
}

static ssize_t hpbiospw_read(struct file *file, char __user *ubuf,
			     size_t count, loff_t *ppos)
{
	return simple_read_from_buffer(ubuf, count, ppos, hpbiospw_result,
				       hpbiospw_result_len);
}

static const struct proc_ops hpbiospw_proc_ops = {
	.proc_write = hpbiospw_write,
	.proc_read  = hpbiospw_read,
};

static struct proc_dir_entry *hpbiospw_entry;

static int __init hpbiospw_init(void)
{
	hpbiospw_entry = proc_create("hp_biospw", 0600, NULL, &hpbiospw_proc_ops);
	if (!hpbiospw_entry)
		return -ENOMEM;
	pr_info("hp_biospw: loaded (write 2-3 NUL-separated fields to /proc/hp_biospw)\n");
	return 0;
}

static void __exit hpbiospw_exit(void)
{
	proc_remove(hpbiospw_entry);
	pr_info("hp_biospw: unloaded\n");
}

module_init(hpbiospw_init);
module_exit(hpbiospw_exit);

MODULE_LICENSE("GPL");
MODULE_DESCRIPTION("Send one HP WMI BIOS-setting request from userspace");
