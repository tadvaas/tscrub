/*******************************************************************************

  hpuefi.c
  
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

#include <linux/version.h>
#include <linux/init.h>
#include <linux/module.h>
#include <linux/kernel.h>
#include <linux/slab.h>
#include <linux/fs.h>
#include <linux/mm.h>
#include <linux/cdev.h>
#include <linux/semaphore.h>
#include <asm/io.h>
#include "hpuefi.h"
#include <linux/uaccess.h>

MODULE_DESCRIPTION("HP UEFI Driver");
MODULE_LICENSE("GPL");

//
// Prototypes
//

// initialization routine - called by kernel on module load (insmod)
static int UefiDriverInit(void);
// exit/cleanup routine - called by kernel on module unload (rmmod)
static void UefiDriverExit(void);
// device open
static int UefiDriverOpen(struct inode *inode, struct file *filp);
// device close
static int UefiDriverRelease(struct inode *inode, struct file *filp);
// device mmap
static int UefiDriverMmap(struct file *filp, struct vm_area_struct *vma);
// IOCTL
static long UefiIOCTL(struct file *f, unsigned int cmd, unsigned long arg);
//
// Globals
//

// standard operations supported by the device
static struct file_operations UefiDriverFileOperations =
{
   .owner   = THIS_MODULE,
   .mmap    = UefiDriverMmap,
   .open    = UefiDriverOpen,
   .release = UefiDriverRelease,
   .unlocked_ioctl = UefiIOCTL
};

// pointer to unaligned memory area
static uint8_t *BufferPtr = NULL;
// pointer to page aligned memory area
static uint8_t *AlignedPtr = NULL;

// the device number for character device (embeds both major and minor numbers)
// is assigned when allocated
static dev_t CharDev;
// the major number portion of the character device
static uint32_t DevMajor = DEV_MAJOR_UNINITIALIZED;
// structure used to tie file_operations mappings to character device
static struct cdev CharDevStruct;

// a semaphore to ensure there can be only one user of this driver at a time
static struct semaphore Sem;

static uint32_t ActualBufferSize = 0;
static uint32_t PhysBuffer = 0;

typedef struct {
   unsigned short Command;
   unsigned short Port;
   unsigned int Buffer;
} IOCTL_ARG;
#define DOSWSMI          _IOW('q', 1, IOCTL_ARG)
#define SET_PHYS_BUFFER  _IOW('q', 2, IOCTL_ARG)
//
// Functions
//

// Prototype for some compiler warnings
unsigned int DoSwSmi(const unsigned short, const unsigned short, const unsigned int);

unsigned int DoSwSmi(const unsigned short AxFunction, const unsigned short DxSmiCmdPort, const unsigned int EdiBufferPhysicalAddress)
{
   unsigned int flags_reg;

   // extended inline AT&T assembly to perform SW SMI
   // register usage described below
   asm volatile(
               "clc\n\t"                  // clear the carry flag, just in case
               "inc  %%dx\n\t"            // Inc DX to point to the data port
               "xchg %%ah, %%al\n\t"
               "outb %%al, %%dx\n\t"      // write al (data) to dx (data port)
               "dec  %%dx\n\t"            // Dec DX to point to the SMI port
               "xchg %%ah, %%al\n\t"
               "outb %%al, %%dx\n\t"      // write al (function) to dx (SMI port)
               "movw $0x86, %%dx\n\t"     // these 3 lines may not strictly be needed
               "outb %%al, %%dx\n\t"      // ... just writing to port 86h to ensure
               "outb %%al, %%dx\n\t"      // ... that SMI is done before we go back
               "pushfw\n\t"               // push flags
               "xorl %0, %0\n\t"          // clear an unused register (=r below)
               "popw %w0\n\t"             // pop flags into newly cleared register
               // output: uses any unused register to stuff value of flags in flags_reg
               : "=r" (flags_reg)
               // input: automatic population of AX, DX, and EDI in advance
               : "a" (AxFunction), "d" (DxSmiCmdPort), "D" (EdiBufferPhysicalAddress)
               // clobber: let compiler know that memory and flags may have changed
               : "memory", "cc"
               );
   return flags_reg;
}

// Prototype for some compiler warnings
void SetPhysBuffer(const unsigned int); 

void SetPhysBuffer(const unsigned int PhysicalAddress) {
    uint8_t *PagePtr;

    if (BufferPtr == NULL) {
        if (PhysicalAddress == 0) {
            // get contiguous memory a couple of pages larger than we need.
            // memory returned from kmalloc has a kernel logical address, which
            // differs from its physical address by a constant offset.
            // GFP_DMA forces allocation in lower 24-bit addressing range (<16MB)
            BufferPtr = kmalloc(PADDED_BUFFER_SIZE, GFP_KERNEL | GFP_DMA);
            ActualBufferSize = PADDED_BUFFER_SIZE;
        if (BufferPtr == NULL)
        {
           printk(KERN_WARNING "hpuefi: unable to allocate memory\n");
        }
        else {
               // get page-aligned pointer within buffer.
        // virt_to_page returns a struct page pointer for the supplied kernel logical address
        // page_address takes that struct page pointer, and returns the page's address
        // the following call rounds the address down to the nearest page
        AlignedPtr = page_address(virt_to_page(BufferPtr));
        // if the buffer's address is higher than the page address, it's not page-aligned
        // so we have to bump up to the next page
        if (BufferPtr > AlignedPtr)
        {
           AlignedPtr += PAGE_SIZE;
        }
        // reserve all pages to prevent them from being paged out.
        // virt_to_page() takes a kernel logical address and returns its
        // associated struct page pointer
        for (PagePtr = AlignedPtr; PagePtr < AlignedPtr + BUFFER_SIZE; PagePtr += PAGE_SIZE)
        {
           MEM_MAP_RESERVE(virt_to_page(PagePtr));
        }
        }

        } else {
            BufferPtr = ioremap_cache(PhysicalAddress, BUFFER_SIZE);
            PhysBuffer = PhysicalAddress;
            AlignedPtr = BufferPtr;
            ActualBufferSize = BUFFER_SIZE;

        }
    }
}


static long UefiIOCTL(struct file *f, unsigned int cmd, unsigned long arg)
{
    IOCTL_ARG  IoctlArg;
    switch (cmd)
    {
        case DOSWSMI:
            if (copy_from_user(&IoctlArg, (IOCTL_ARG *)arg, sizeof(IOCTL_ARG)))
            {
                return -EACCES;
            }
            DoSwSmi(IoctlArg.Command, IoctlArg.Port, IoctlArg.Buffer);
            break;
        case SET_PHYS_BUFFER:
            if (copy_from_user(&IoctlArg, (IOCTL_ARG *)arg, sizeof(IOCTL_ARG)))
            {
                return -EACCES;
            }
            SetPhysBuffer(IoctlArg.Buffer);
            break;
        default:
            return -EINVAL;
    }
 
    return 0;
}

/******************************************************************************
Function:  UefiDriverInit

Summary:
  Driver initialization routine called by kernel when module is loaded (e.g.
  by using insmod).

Preconditions:

  System State:      None

  Global Variables:  None

  Parameters:        None

Postconditions:

  System State:
    Memory has been reserved in the lower 24-bit addressing space (<16MB), a
    semaphore has been initialized to protect the buffer from multiple
    concurrent users, and the hpuefi character device has been created, and
    is ready for opening.

  Global Variables:
    ---------------------------------------------------------------------------
    Name:            BufferPtr
    Valid values:    Any non-null pointer.
    ---------------------------------------------------------------------------
    Name:            AlignedPtr
    Valid values:    A page-aligned non-null pointer within buffer pointed to
                     by BufferPtr.
    ---------------------------------------------------------------------------
    Name:            Sem
    Valid values:    1 (device available)
    ---------------------------------------------------------------------------
    Name:            CharDev
    Valid values:    A 32-bit unsigned number (upper 12 bits => major,
                                               lower 20 bits => minor)
    ---------------------------------------------------------------------------
    Name:            DevMajor
    Valid values:    A 12-bit number (0 - 4095d)
    ---------------------------------------------------------------------------
    Name:            CharDevStruct
    Valid values:    Initialized with the file operations valid for this driver
    ---------------------------------------------------------------------------

  Parameters:        None

  Function Returns:  int
    ---------------------------------------------------------------------------
    SUCCESS (0)      Function successfully performed its task
    -ENOMEM          Unable to allocate enough kernel memory
    -EIO             Unable to create character device
    ---------------------------------------------------------------------------

Side Effects:        None

Details:             None

******************************************************************************/
static int UefiDriverInit(void)
{
   int Status = SUCCESS;
  
   {
      // initialize the semaphore, to be used in open and release functions
      // value is set initially to 1, meaning that it's available
      // open decrements the value, making it no longer available
      // release increments it to make it available again
      sema_init(&Sem, SEM_AVAILABLE);

      // once memory and semaphore are set up, get a dynamically allocated character device number
      if ((alloc_chrdev_region(&CharDev, DEV_MINOR, DEV_COUNT, "hpuefi")) < 0)
      {
         printk(KERN_WARNING "hpuefi: unable to get character device\n");
         Status = -EIO;
      }
      else
      {
         // extract and save off major device number for deallocation at driver exit
         DevMajor = MAJOR(CharDev);
         // initialize char device structure to associate with our file ops
         cdev_init(&CharDevStruct, &UefiDriverFileOperations);
         CharDevStruct.owner = THIS_MODULE;
         CharDevStruct.ops = &UefiDriverFileOperations;
         // map char device structure to char device we just allocated
         if (cdev_add(&CharDevStruct, CharDev, DEV_COUNT) < 0)
         {
            printk(KERN_WARNING "hpuefi: error adding char device\n");
            Status = -EIO;
         }
      }
   }
   if (Status != SUCCESS)
   {
      // something went wrong - call exit/cleanup function to release what we've allocated
      UefiDriverExit();
   }

   return Status;
}

/******************************************************************************
Function:  UefiDriverExit

Summary:
  Driver exit and cleanup routine called by kernel when module is unloaded
  (e.g. by using rmmod), or on error during init.

Preconditions:

  System State:      None

  Global Variables:  None

  Parameters:        None

Postconditions:

  System State:
    Character device has been unregistered, and memory has been unreserved
    and freed.

  Global Variables:
    ---------------------------------------------------------------------------
    Name:            BufferPtr
    Valid values:    NULL
    ---------------------------------------------------------------------------
    Name:            AlignedPtr
    Valid values:    NULL
    ---------------------------------------------------------------------------

  Parameters:        None

  Function Returns:  None

Side Effects:        None

Details:             None

******************************************************************************/
static void UefiDriverExit(void)
{
   uint8_t *PagePtr;

   // unregister the device, if it was successfully registered
   if (DevMajor > DEV_MAJOR_UNINITIALIZED)
   {
      cdev_del(&CharDevStruct);
      unregister_chrdev_region(CharDev, DEV_COUNT);
   }

   if (PhysBuffer == 0 ) {
       // and free the allocated memory
       if (BufferPtr != NULL)
       {
          // unreserve all pages
          for (PagePtr = AlignedPtr; PagePtr < AlignedPtr + BUFFER_SIZE; PagePtr += PAGE_SIZE)
          {
             MEM_MAP_UNRESERVE(virt_to_page(PagePtr));
          }
          kfree(BufferPtr);
       }
   } else {
       iounmap(BufferPtr);
       PhysBuffer = 0;
   }

   AlignedPtr = NULL;
   BufferPtr = NULL;

   printk(KERN_INFO "hpuefi: unloaded\n");
   return;
}

/******************************************************************************
Function:  UefiDriverOpen

Summary:
  Called when the device is opened. If the semaphore is available, the buffer
  is cleared, and populated with its physical address.

Preconditions:

  System State:      None

  Global Variables:
    ---------------------------------------------------------------------------
    Name:            BufferPtr
    Valid values:    Any non-null pointer.
    ---------------------------------------------------------------------------
    Name:            AlignedPtr
    Valid values:    A page-aligned non-null pointer within buffer pointed to
                     by BufferPtr.
    ---------------------------------------------------------------------------
    Name:            Sem
    Valid values:    1 (device available)
    ---------------------------------------------------------------------------

  Parameters:
    ---------------------------------------------------------------------------
    Name:            inode
    Type:            struct inode *
    Description:     Pointer to inode structure used internally by kernel to
                     represent files and provide access to file information.
    Valid values:    Not used in this function.
    ---------------------------------------------------------------------------
    Name:            filp
    Type:            struct file *
    Description:     Pointer to a kernel file structure (for an open file).
    Valid values:    Not used in this function.
    ---------------------------------------------------------------------------

Postconditions:

  System State:
    The device is locked by the current user, the buffer is cleared, and pre-
    populated with its physical address.

  Global Variables:
    ---------------------------------------------------------------------------
    Name:            Sem
    Valid values:    0 (device not available)
    ---------------------------------------------------------------------------

  Parameters:        None

  Function Returns:  int
    ---------------------------------------------------------------------------
    SUCCESS (0)      Function successfully performed its task
    -ERESTARTSYS     Semaphore is not available (delays new open requests)
    ---------------------------------------------------------------------------

Side Effects:        None

Details:             None

******************************************************************************/
static int UefiDriverOpen(struct inode *inode, struct file *filp)
{
   int Status = SUCCESS;

   // grab the semaphore when someone attempts to use the driver
   if (down_interruptible(&Sem) != SUCCESS)
   {
      Status = -ERESTARTSYS;
   }
   return Status;
}

/******************************************************************************
Function:  UefiDriverRelease

Summary:
  Called when the device is closed. Releases semaphore and clears the buffer.
  Note that the release function is only guaranteed to be called on close
  for this driver because only one open is allowed at a time (otherwise, it
  would only be called when all shared copies were closed).

Preconditions:

  System State:      None

  Global Variables:  
    ---------------------------------------------------------------------------
    Name:            BufferPtr
    Valid values:    Any non-null pointer.
    ---------------------------------------------------------------------------
    Name:            Sem
    Valid values:    0 (device not available)
    ---------------------------------------------------------------------------

  Parameters:
    ---------------------------------------------------------------------------
    Name:            inode
    Type:            struct inode *
    Description:     Pointer to inode structure used internally by kernel to
                     represent files and provide access to file information.
    Valid values:    Not used in this function.
    ---------------------------------------------------------------------------
    Name:            filp
    Type:            struct file *
    Description:     Pointer to a kernel file structure (for an open file).
    Valid values:    Not used in this function.
    ---------------------------------------------------------------------------

Postconditions:

  System State:
    The buffer is cleared, and the semaphore is released, making the device
    available for the next user.

  Global Variables:
    ---------------------------------------------------------------------------
    Name:            Sem
    Valid values:    1 (device available)
    ---------------------------------------------------------------------------

  Parameters:        None

  Function Returns:  int
    ---------------------------------------------------------------------------
    SUCCESS (0)      Function successfully performed its task
    ---------------------------------------------------------------------------

Side Effects:        None

Details:             None

******************************************************************************/
static int UefiDriverRelease(struct inode *inode, struct file *filp)
{
   // zero out the memory when the current user is finished with it
   memset(BufferPtr, 0, ActualBufferSize);

   // release the semaphore when the current user is finished
   up(&Sem);
   return SUCCESS;
}

/******************************************************************************
Function:  UefiDriverMmap

Summary:
  Called on attempt to mmap (memory map) the device.

Preconditions:

  System State:
    Driver previously opened with call to UefiDriverOpen().

  Global Variables:  None

  Parameters:        None
    ---------------------------------------------------------------------------
    Name:            filp
    Type:            struct file *
    Description:     Pointer to a kernel file structure (for an open file).
    Valid values:    Not used in this function.
    ---------------------------------------------------------------------------
    Name:            vma
                        ->vm_end
                        ->vm_start
                        ->vm_flags
    Type:            struct vm_area_struct *
    Description:     This pointer to the virtual memory area being mapped
                     includes size and location information used to validate
                     the request, as well as flags for the type of mapping.
    Valid values:    Requested mapping size must not exceed size of buffer.
    ---------------------------------------------------------------------------

Postconditions:

  System State:
    The buffer is mapped to the user's memory addressing space.

  Global Variables:
    ---------------------------------------------------------------------------
    Name:            AlignedPtr
    Valid values:    A page-aligned non-null pointer.
    ---------------------------------------------------------------------------

  Parameters:
    ---------------------------------------------------------------------------
    Name:            vma
                        ->vm_flags
    Type:            struct vm_area_struct *
    Description:     Flags to describe the virtual memory area being mapped.
    Valid values:    Flags are updated to lock memory, so that the mapped area
                     cannot be swapped out.
    ---------------------------------------------------------------------------

  Function Returns:  int
    ---------------------------------------------------------------------------
    SUCCESS (0)      Function successfully performed its task
    -ENXIO           No such device or address
    ---------------------------------------------------------------------------

Side Effects:        None

Details:             None

******************************************************************************/
static int UefiDriverMmap(struct file *filp, struct vm_area_struct *vma)
{
   int Status = SUCCESS;
   uintptr_t size = vma->vm_end - vma->vm_start;
   if (BufferPtr == NULL) {
       SetPhysBuffer(0);
   }

  // zero out the memory and pre-populate with physical address
  memset(BufferPtr, 0, ActualBufferSize);
  if (PhysBuffer == 0) {
     *(uintptr_t *)AlignedPtr = virt_to_phys(AlignedPtr);
  } else {
     *(uintptr_t *)AlignedPtr = PhysBuffer;
  }


/* *****Input conditions validation preamble start***** */
   if (size > BUFFER_SIZE)
   {
      printk(KERN_WARNING "hpuefi: mmap requested size too large\n");
      return -ENXIO;
   }
/* *****Input conditions validation preamble end***** */

   // we do not want to have this area swapped out, lock it

   // Due to patch behaviors of various distros, former LINUX_VERSION_CODE
   // test insufficient for data structure compatibility accommodation.
   // This replacement string will be exchanged in following manner as
   // detected by fixup script with environment check (not compile-time
   // allowance)
   // 
   // #if LINUX_VERSION_CODE < KERNEL_VERSION(6,3,0)
   //    // we do not want to have this area swapped out, lock it
   //    vma->vm_flags |= VM_LOCKED;		// pre 6.3.0 kernel 
   // #else
   //    vm_flags_set(vma, VM_LOCKED);		// priv access intererface
   // #endif
   // 

   vm_flags_set(vma, VM_LOCKED) ;


   // create mapping between virtual address supplied by user and physical pages
   if (PhysBuffer != 0) {
       if ((remap_pfn_range(vma, vma->vm_start, PhysBuffer >> PAGE_SHIFT, size, vma->vm_page_prot)) != SUCCESS)
       {
           printk(KERN_WARNING "hpuefi: remap page range failed\n");
           Status = -ENXIO;
       }
   } else {
       if ((remap_pfn_range(vma, vma->vm_start, page_to_pfn(virt_to_page(AlignedPtr)), size, vma->vm_page_prot)) != SUCCESS)
           {
           printk(KERN_WARNING "hpuefi: remap page range failed\n");
           Status = -ENXIO;
       }
   }
   return Status;
}

//
// Macro to register initialization function
//
module_init(UefiDriverInit);

//
// Macro to register exit/cleanup function
//
module_exit(UefiDriverExit);

MODULE_LICENSE("GPL");
MODULE_DESCRIPTION("HP UEFI BIOS utilities driver");
