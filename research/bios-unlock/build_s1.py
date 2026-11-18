import hashlib, shutil, os
BIN='/Users/user/Documents/Repositories/tscrub/research/bios-unlock/BINs'
POL=0x18cc36e  # AdminAtPowerOnAuthPolicy data byte (value 01 -> 00)
def build(src, dst, label):
    d=bytearray(open(src,'rb').read())
    assert d[POL]==1, 'policy byte not 1 at 0x%x (got %02x)' % (POL, d[POL])
    d[POL]=0
    open(dst,'wb').write(bytes(d))
    print('%s: %s md5 %s' % (label, dst, hashlib.md5(bytes(d)).hexdigest()))
build(os.path.join(BIN,'W25Q256FV_SOIC8-CND14004KD.BIN'),
      os.path.join(BIN,'W25Q256FV_SOIC8-CND14004KD-S1.BIN'), 'S1 (orig + policy 0)')
build(os.path.join(BIN,'W25Q256FV_SOIC8-CND14004KD-UNLOCKED-V3.BIN'),
      os.path.join(BIN,'W25Q256FV_SOIC8-CND14004KD-S2.BIN'), 'S2 (V3 empty + policy 0)')
