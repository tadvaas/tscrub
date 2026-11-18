import hashlib, os
BIN='/Users/user/Documents/Repositories/tscrub/research/bios-unlock/BINs'
d0=open(os.path.join(BIN,'W25Q256FV_SOIC8-CND14004KD.BIN'),'rb').read()
def entry_off(o):
    ns=int.from_bytes(d0[o+0x24:o+0x28],'little')
    nm=d0[o+0x3c:o+0x3c+ns].decode('utf-16-le','replace')
    return o+0x3c+ns, ns, nm
def save(img,name):
    out=os.path.join(BIN,name)
    open(out,'wb').write(bytes(img))
    print('%s md5 %s' % (name, hashlib.md5(bytes(img)).hexdigest()))
# S4: ClearSecurityOperation = 1
o,ns,nm=entry_off(0x18d2070)
print('ClearSecurityOperation: ns=%d name=%r data=0x%02x' % (ns,nm,d0[o]))
img=bytearray(d0); img[o]=1
save(img,'W25Q256FV_SOIC8-CND14004KD-S4.BIN')
# S5: UserCred attrs 07 -> 00
img=bytearray(d0)
assert img[0x18d4a58+4:0x18d4a58+8]==b'\x07\x00\x00\x00'
img[0x18d4a58+4:0x18d4a58+8]=b'\x00\x00\x00\x00'
save(img,'W25Q256FV_SOIC8-CND14004KD-S5.BIN')
