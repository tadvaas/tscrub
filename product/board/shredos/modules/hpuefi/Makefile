#
# Makefile for hpuefi loadable kernel module
#
KVERS:=$(shell uname -r)
KSRC:=/lib/modules/$(KVERS)/build
KDIR:=/lib/modules/$(KVERS)/kernel/drivers/hpuefi
DESTDIR:=
TARGET=hpuefi

obj-m:=$(TARGET).o

default:
	./hpuefi-vm-flags.sh
	$(MAKE) -C $(KSRC) M=$(shell pwd) modules
clean:
	$(RM) .*.cmd *.mod.c *.o *.ko *.order *.symvers -r .tmp*
install:
	install -D -m 0644 $(TARGET).ko $(DESTDIR)$(KDIR)/$(TARGET).ko
	depmod
	install -D -m 0744 mkdevhpuefi $(DESTDIR)$(KDIR)
