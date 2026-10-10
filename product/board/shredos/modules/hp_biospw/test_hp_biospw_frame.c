/*
 * test_hp_biospw_frame.c — byte-exact tests for the HP WMI request frame.
 *
 * Runs on a development host (no kernel, no hardware):
 *
 *   cc -std=c11 -Wall -Wextra -Werror -o /tmp/t test_hp_biospw_frame.c && /tmp/t
 *
 * The frame format comes from the firmware's own AML parser, and the reference
 * value below is the frame that actually cleared a BIOS password on a real
 * HP EliteBook 830 G5 (research/bios-unlock/18-live-wmi-probing-results.md,
 * where the kernel log recorded it as 86 bytes).
 */
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

#include "hp_biospw_frame.h"

static int failures;
static int checks;

static void fail(const char *label, const char *msg)
{
	failures++;
	printf("  FAIL %s: %s\n", label, msg);
}

static void ok(const char *label, const char *msg)
{
	checks++;
	(void)label;
	(void)msg;
}

/* Compare produced bytes against a hex string ("1c0053006500" …). */
static void expect_hex(const char *label, const unsigned char *got,
		       size_t gotlen, const char *hex)
{
	size_t want = strlen(hex) / 2;
	size_t i;
	char buf[64];

	if (strlen(hex) % 2) {
		fail(label, "bad expectation");
		return;
	}
	if (want != gotlen) {
		snprintf(buf, sizeof(buf), "length %zu, expected %zu",
			 gotlen, want);
		fail(label, buf);
		return;
	}
	for (i = 0; i < want; i++) {
		char pair[3] = { hex[i * 2], hex[i * 2 + 1], 0 };
		unsigned char b = (unsigned char)strtoul(pair, NULL, 16);

		if (b != got[i]) {
			snprintf(buf, sizeof(buf),
				 "byte %zu is %02x, expected %02x", i, got[i], b);
			fail(label, buf);
			return;
		}
	}
	ok(label, "matches");
}

static void test_elem_empty(void)
{
	unsigned char out[HPBIOSPW_MAX_FRAME];
	size_t n = hpbiospw_elem(out, sizeof(out), "");

	/* The BIOS expects four bytes for an empty string: length 2 + one
	 * UTF-16 NUL. A zero-length element makes the parser run off the end. */
	expect_hex("elem(\"\")", out, n, "02000000");
}

static void test_elem_ascii(void)
{
	unsigned char out[HPBIOSPW_MAX_FRAME];
	size_t n;

	n = hpbiospw_elem(out, sizeof(out), "A");
	expect_hex("elem(\"A\")", out, n, "02004100");

	n = hpbiospw_elem(out, sizeof(out), "AB");
	expect_hex("elem(\"AB\")", out, n, "040041004200");

	/* The credential prefix the driver itself uses (bioscfg.h UTF_PREFIX). */
	n = hpbiospw_elem(out, sizeof(out), "<utf-16/>");
	expect_hex("elem(\"<utf-16/>\")", out, n,
		   "12003c007500740066002d00310036002f003e00");
}

static void test_elem_utf8(void)
{
	unsigned char out[HPBIOSPW_MAX_FRAME];
	size_t n;

	/* U+00E9 (é) = 1 code unit. */
	n = hpbiospw_elem(out, sizeof(out), "\xc3\xa9");
	expect_hex("elem(\"é\")", out, n, "0200e900");

	/* U+1F600 = surrogate pair D83D DE00 as two code units. */
	n = hpbiospw_elem(out, sizeof(out), "\xf0\x9f\x98\x80");
	expect_hex("elem(U+1F600)", out, n, "04003dd800de");
}

static void test_frame_reference(void)
{
	/* [Setup Password][<utf-16/>][<utf-16/>hpinvent]
	 *   = 30 + 20 + 36 = 86 bytes, the frame the BIOS accepted. */
	const char *fields[3] = { "Setup Password", "<utf-16/>",
				  "<utf-16/>hpinvent" };
	unsigned char out[HPBIOSPW_MAX_FRAME];
	size_t n = hpbiospw_frame(out, sizeof(out), fields, 3);

	expect_hex("the clear frame",
		   out, n,
		   "1c00"
		   "530065007400750070002000"
		   "500061007300730077006f0072006400"
		   "12003c007500740066002d00310036002f003e00"
		   "2200"
		   "3c007500740066002d00310036002f003e00"
		   "6800700069006e00760065006e007400");
}

static void test_frame_two_fields(void)
{
	/* The corroboration frame: name + value, no credential at all. */
	const char *fields[2] = { "Ownership Tag", "x" };
	unsigned char out[HPBIOSPW_MAX_FRAME];
	size_t n = hpbiospw_frame(out, sizeof(out), fields, 2);

	expect_hex("two-field frame", out, n,
		   "1a004f0077006e006500720073006800690070002000540061006700"
		   "02007800");
}

static void test_rejections(void)
{
	const char *two[2] = { "a", "b" };
	const char *one[1] = { "a" };
	const char *four[4] = { "a", "b", "c", "d" };
	unsigned char out[HPBIOSPW_MAX_FRAME];
	char big[HPBIOSPW_MAX_FIELD + 2];

	/* Too few / too many elements — the BIOS allows 2..3. */
	if (hpbiospw_frame(out, sizeof(out), one, 1) != 0)
		fail("1 field", "should be rejected");
	else
		ok("1 field", "rejected");

	if (hpbiospw_frame(out, sizeof(out), four, 4) != 0)
		fail("4 fields", "should be rejected");
	else
		ok("4 fields", "rejected");

	/* A buffer one byte too small must not be written past. */
	if (hpbiospw_frame(out, 5, two, 2) != 0)
		fail("short buffer", "should be rejected");
	else
		ok("short buffer", "rejected");

	/* Invalid UTF-8 must be refused rather than silently sent as garbage. */
	if (hpbiospw_elem(out, sizeof(out), "\x80") != 0)
		fail("invalid utf8", "should be rejected");
	else
		ok("invalid utf8", "rejected");

	/* An over-long field is refused (512 bytes of UTF-8 max). */
	memset(big, 'a', sizeof(big) - 1);
	big[sizeof(big) - 1] = '\0';
	if (hpbiospw_elem(out, sizeof(out), big) != 0)
		fail("long field", "should be rejected");
	else
		ok("long field", "rejected");
}

int main(void)
{
	printf("hp_biospw frame tests\n");
	test_elem_empty();
	test_elem_ascii();
	test_elem_utf8();
	test_frame_reference();
	test_frame_two_fields();
	test_rejections();

	printf("%d check(s), %d failure(s)\n", checks, failures);
	if (failures)
		return 1;
	printf("OK\n");
	return 0;
}
