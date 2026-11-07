/*******************************************************************************

  hpuefi.h
  
  Kernel driver for Linux UEFI BIOS utilities
  © Copyright 2011 Hewlett-Packard Development Company, L.P.

  This program is free software; you can redistribute it and/or modify it
  under the terms of version 2 of the GNU General Public License as published
  by the Free Software Foundation.

  This program is distributed in the hope that it will be useful, but WITHOUT
  ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
  FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
  more details.

  You should have received a copy of the GNU General Public License along with
  this program; if not, write to:
  
    Free Software Foundation, Inc.
    51 Franklin Street, Fifth Floor
    Boston, MA 02110-1301, USA.

  The full GNU General Public License is included in this distribution in
  the file called "COPYING".

*******************************************************************************/

#ifndef _HPUEFI_H_
#define _HPUEFI_H_

#include <linux/page-flags.h>    // for PG_reserved
#include <asm/page.h>            // for PAGE_SIZE

// Due to patch behaviors of various distros, former LINUX_VERSION_CODE
// test insufficient for data structure compatibility accommodation.
// This replacement string will be exchanged in following manner as
// detected by fixup script with environment check (not compile-time
// allowance)
// 
// #if LINUX_VERSION_CODE < KERNEL_VERSION(6,18,0)
//    &((p)->flags)		// pre 6.18.0 kernel 
// #else
//    &((p)->flags.f)		// 6.18.0 kernel or later
// #endif

// macros used for memory reservation
// (disallows paging for struct page pointer p)
#define MEM_MAP_RESERVE(p) set_bit(PG_reserved, &((p)->flags.f))
#define MEM_MAP_UNRESERVE(p) clear_bit(PG_reserved, &((p)->flags.f))

// all functions return 0 for success
#define SUCCESS 0

// size of memory buffer
#define BUFFER_SIZE (64u * 1024u)
#define PADDED_BUFFER_SIZE (BUFFER_SIZE + (2u * PAGE_SIZE))

// driver creates one major device (minor 0)
#define DEV_MAJOR_UNINITIALIZED 0u
#define DEV_MINOR 0u
#define DEV_COUNT 1u

// initializing semaphore to "1" makes it available
#define SEM_AVAILABLE 1

#endif /* _HPUEFI_H_ */
