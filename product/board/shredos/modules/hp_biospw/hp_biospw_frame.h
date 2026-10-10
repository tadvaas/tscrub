/* SPDX-License-Identifier: GPL-2.0 */
/*
 * hp_biospw_frame.h — build the HP WMI BIOS-setting request frame.
 *
 * Pure C, no kernel dependencies, so the exact bytes can be unit-tested on a
 * development host (see test_hp_biospw_frame.c). Header-only (static inline) so
 * both the kernel module and the userspace test compile from this one source.
 *
 * The frame is what the firmware's own AML parser (`Method (WSTB)` in the DSDT,
 * see research/bios-unlock/16-…/18-…) expects: a sequence of up to three
 * length-prefixed UTF-16LE elements
 *
 *     <u16 byte_length><UTF-16LE payload>   repeated
 *
 * written as [name][value][credential]. The BIOS appends a UTF-16 NUL to each
 * element itself, so the payload must NOT include one — this mirrors
 * hp_ascii_to_utf16_unicode() in drivers/platform/x86/hp/hp-bioscfg.
 *
 * Two details that matter and are easy to get wrong:
 *
 *  - An *empty* element is not zero-length. The driver is explicit that the BIOS
 *    expects four bytes ("02 00 00 00"): a length field of 2 followed by one
 *    UTF-16 NUL. An empty credential written as a zero-length element makes the
 *    parser run off the end and the BIOS answer 0x04.
 *  - The declared length is the *byte* length of the UTF-16 payload. Non-ASCII
 *    input is converted properly (including surrogate pairs), and the declared
 *    length counts the code units actually emitted — unlike the in-tree driver,
 *    whose length is derived from the UTF-8 byte count and therefore disagrees
 *    with what it writes for any non-ASCII password.
 */
#ifndef HP_BIOSPW_FRAME_H
#define HP_BIOSPW_FRAME_H

#define HPBIOSPW_MAX_FIELDS 3
#define HPBIOSPW_MAX_FIELD  512	/* bytes of UTF-8 per field */
/* Worst case: every byte becomes a surrogate pair. */
#define HPBIOSPW_MAX_FRAME  (HPBIOSPW_MAX_FIELDS * (2 + HPBIOSPW_MAX_FIELD * 4))

/* Number of UTF-16 code units `len` bytes of UTF-8 produce.
 * Sets *ok to 0 and returns 0 on invalid UTF-8. */
static inline unsigned int hpbiospw_units(const char *s, unsigned int len,
					  int *ok)
{
	unsigned int units = 0, i = 0;

	*ok = 1;
	while (i < len) {
		unsigned char c = (unsigned char)s[i];
		unsigned int need, k;
		unsigned long cp;

		if (c < 0x80) {
			cp = c;
			need = 1;
		} else if ((c & 0xE0) == 0xC0) {
			cp = c & 0x1F;
			need = 2;
		} else if ((c & 0xF0) == 0xE0) {
			cp = c & 0x0F;
			need = 3;
		} else if ((c & 0xF8) == 0xF0) {
			cp = c & 0x07;
			need = 4;
		} else {	/* continuation byte or invalid lead */
			*ok = 0;
			return 0;
		}

		if (i + need > len) {
			*ok = 0;
			return 0;
		}
		for (k = 1; k < need; k++) {
			unsigned char cc = (unsigned char)s[i + k];

			if ((cc & 0xC0) != 0x80) {
				*ok = 0;
				return 0;
			}
			cp = (cp << 6) | (cc & 0x3F);
		}
		i += need;

		/* Reject overlong/surrogate/out-of-range (cheap sanity only). */
		if (cp > 0x10FFFF || (cp >= 0xD800 && cp <= 0xDFFF)) {
			*ok = 0;
			return 0;
		}
		units += (cp >= 0x10000) ? 2 : 1;
	}
	return units;
}

/* Write one element. Returns bytes written, or 0 on overflow/invalid input. */
static inline size_t hpbiospw_elem(unsigned char *dst, size_t cap,
				   const char *s)
{
	unsigned int len = 0, units, i = 0, out;
	int ok;

	while (s[len] != '\0') {
		if (len >= HPBIOSPW_MAX_FIELD)
			return 0;
		len++;
	}

	if (len == 0) {			/* "BIOS expects 4 bytes for empty" */
		if (cap < 4)
			return 0;
		dst[0] = 0x02;
		dst[1] = 0x00;
		dst[2] = 0x00;
		dst[3] = 0x00;
		return 4;
	}

	units = hpbiospw_units(s, len, &ok);
	if (!ok || units == 0 || units > 0x7FFF)
		return 0;
	if (cap < 2 + (size_t)units * 2)
		return 0;

	dst[0] = (unsigned char)((units * 2) & 0xff);
	dst[1] = (unsigned char)(((units * 2) >> 8) & 0xff);
	out = 2;

	while (i < len) {
		unsigned char c = (unsigned char)s[i];
		unsigned int need, k;
		unsigned long cp;

		if (c < 0x80) {
			cp = c;
			need = 1;
		} else if ((c & 0xE0) == 0xC0) {
			cp = c & 0x1F;
			need = 2;
		} else if ((c & 0xF0) == 0xE0) {
			cp = c & 0x0F;
			need = 3;
		} else {
			cp = c & 0x07;
			need = 4;
		}
		for (k = 1; k < need; k++)
			cp = (cp << 6) | ((unsigned char)s[i + k] & 0x3F);
		i += need;

		if (cp >= 0x10000) {	/* surrogate pair */
			unsigned long v = cp - 0x10000;

			dst[out++] = (unsigned char)((0xD800 + (v >> 10)) & 0xff);
			dst[out++] = (unsigned char)((0xD800 + (v >> 10)) >> 8);
			dst[out++] = (unsigned char)((0xDC00 + (v & 0x3FF)) & 0xff);
			dst[out++] = (unsigned char)((0xDC00 + (v & 0x3FF)) >> 8);
		} else {
			dst[out++] = (unsigned char)(cp & 0xff);
			dst[out++] = (unsigned char)(cp >> 8);
		}
	}
	return out;
}

/* Build the whole frame from 2 or 3 fields. Returns bytes written, 0 on error. */
static inline size_t hpbiospw_frame(unsigned char *dst, size_t cap,
				    const char *const *fields,
				    unsigned int nfields)
{
	size_t off = 0;
	unsigned int i;

	if (!dst || !fields)
		return 0;
	if (nfields < 2 || nfields > HPBIOSPW_MAX_FIELDS)
		return 0;

	for (i = 0; i < nfields; i++) {
		size_t w = hpbiospw_elem(dst + off, cap - off, fields[i]);

		if (w == 0)
			return 0;
		off += w;
	}
	return off;
}

#endif /* HP_BIOSPW_FRAME_H */
