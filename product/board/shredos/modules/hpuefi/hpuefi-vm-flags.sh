#!/bin/sh
#
# This helper utility script helps work around changes to kernel data
# structures that may not match their versioned counterparts. Patching
# and selective back-porting activity may not match mainline kernel
# version numbers for internal customizations. This work-around script
# supports most HP workstation customers and their internal needs.
# Based upon customer feedack, this script must determine actual data 
# structure changes active in the kernel build environment. While not 
# optimal, it has proven compatible for most automated environments and 
# packaging structures. (Cross-compiling environments may fail these 
# tests - please adapt this script as necessary for your environment 
# and compile-time needs.)
#
# Last updated: v3.07 - 01/15/2026 ======================================
#
# kernel 6.3.0 : vm_flags_set	   # accessor function <linux/mm.h>
# kernel 6.18  : memdesc_flags_t   # struct page change <linux/mm_types.h>
#
# ---
#
#  Kernel driver for Linux UEFI BIOS utilities
#  (c) Copyright 2018 HP Development Company, L.P.
#
#  This program is free software; you can redistribute it and/or modify it
#  under the terms of version 2 of the GNU General Public License as published
#  by the Free Software Foundation.
#
#  This program is distributed in the hope that it will be useful, but WITHOUT
#  ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
#  FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
#  more details.
#
#  You should have received a copy of the GNU General Public License along with
#  this program; if not, write to:
#  
#    Free Software Foundation, Inc.
#    51 Franklin Street, Fifth Floor
#    Boston, MA 02110-1301, USA.
#
#  The full GNU General Public License is included in this distribution in
#  the file called "COPYING".
#

# Several kernel header path options to check for enterprise Linux versions - 
# which one is active?  Modify paths as necessary to match your build 
# conditions (default script assumption is that runtime kernel version is
# correct):
#
#   /lib/modules/$KVERS/build
#   /usr/src/linux
#   /usr/src/linux-headers-$KVERS

KVERS=`uname -r`
KHPATHS="/lib/modules/$KVERS/build /usr/src/linux /usr/src/linux-headers-$KVERS"
KMMH="build kernel headers not found"
KMMTYPESH="build kernel headers not found"

for thispath in $KHPATHS ; do
    if [ -f $thispath/include/linux/mm.h ]; then
	KMMH=$thispath/include/linux/mm.h ;
	KMMTYPESH=$thispath/include/linux/mm_types.h ;
	break ;
    fi
done

# No build environment found for this kernel
if [ ! -f $KMMH ]; then
    echo "Fatal error - $KVERS build kernel headers not found"
    exit -1
fi

# 6.3.0 =====================================================================
#
# Look for new private flag access interface in mm.h (kernel 6.3.0 or later)
#
KVER630=`grep -c vm_flags_set $KMMH`

# Did we find the correct interface /lib/modules/KVER/build/include/linux/mm.h
#
if [ $KVER630 -eq 0 ]; then
    # Pre 6.3.0 kernel flag-set
    sed -e 's/HP_KVER_VM_FLAGS_LOCKED/vma->vm_flags |= VM_LOCKED/g' hpuefi.c-template > hpuefi.c
else
    # Post 6.3.0 kernel flag-set
    sed -e 's/HP_KVER_VM_FLAGS_LOCKED/vm_flags_set(vma, VM_LOCKED)/g' hpuefi.c-template > hpuefi.c
fi

# 6.18.0 ====================================================================
#
# Look for new flags typedef in mm_types.h (kernel 6.18.0 or later)
#
KVER6180=`grep -c memdesc_flags_t $KMMTYPESH`

# Which flags interface in /lib/modules/KVER/build/include/linux/mm_types.h
#
if [ $KVER6180 -eq 0 ]; then
    # Pre 6.18.0 kernel flag type (unsigned long flags)
    sed -e 's/HP_KVER_PAGE_FLAGS/flags/g' hpuefi.h-template > hpuefi.h
else
    # Post 6.18.0 kernel memdesc_flags_t accessor field
    sed -e 's/HP_KVER_PAGE_FLAGS/flags.f/g' hpuefi.h-template > hpuefi.h
fi

#
exit 0

