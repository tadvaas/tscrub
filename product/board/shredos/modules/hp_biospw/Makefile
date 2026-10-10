# Out-of-tree build of the hp_biospw research/product module.
#
# The image builds this against the Buildroot kernel tree and installs the .ko
# into /lib/modules/<kver>/extra/ (see board/shredos/build_tscrub.sh), so the
# kernel itself needs no patch and no rebuild:
#
#   make -C ~/shredos.x86_64/output/build/linux-6.18 M=$PWD \
#        ARCH=x86_64 \
#        CROSS_COMPILE=$HOME/shredos.x86_64/output/host/bin/x86_64-buildroot-linux-gnu- \
#        modules
#
# The frame encoder is pure C, so its byte-exact behaviour is testable on a
# development host with no kernel and no hardware:
#
#   make test
#
obj-m += hp_biospw.o

HOSTCC ?= cc
CFLAGS_TEST = -std=c11 -O1 -Wall -Wextra -Werror

.PHONY: test clean-test

test: test_hp_biospw_frame.c hp_biospw_frame.h
	$(HOSTCC) $(CFLAGS_TEST) -o /tmp/test_hp_biospw_frame test_hp_biospw_frame.c
	/tmp/test_hp_biospw_frame

clean-test:
	rm -f /tmp/test_hp_biospw_frame
