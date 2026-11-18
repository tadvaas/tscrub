import os, re, struct, lzma, hashlib

BIN='/Users/user/Documents/Repositories/tscrub/research/bios-unlock/BINs'
V3=os.path.join(BIN,'W25Q256FV_SOIC8-CND14004KD-UNLOCKED-V3.BIN')
OUT=os.path.join(BIN,'W25Q256FV_SOIC8-CND14004KD-P3.BIN')

FILE_OFF=0x4D048         # FFS file header (9E21FD93)
SEC_OFF =0x4D060         # GUID-defined section header (EE4E5898)
SEC_SIZE=0x6C16A2
LZMA_OFF=0x4D078
LZMA_LEN=SEC_SIZE-0x18
DEC_SIZE=0x1F5C000
FV_OFF=0x1000
FV_SIZE=0x1F5B000

patches={
 '121': ('mod121_0108.patched.pe', 0x36E768),
 '303': ('mod303_0108.patched.pe', None),
 '107': ('mod107_0108.patched.pe', None),
 '120': ('mod120_0108.patched.pe', None),
 '268': ('mod268_0108.patched.pe', None),
}
mmap={'107':'107 0198','120':'120 0145','268':'268 FFFF','303':'303 0197'}
dump_root=os.path.join(BIN,'CND14004KD_BIOS_region.rgn.dump/3 5473C07A-3DCB-4DCA-BD6F-1E9689E7349A/0 9E21FD93-9C72-4C15-8C4B-E77F1DB2D792/0 EE4E5898-3914-4259-9D6E-DC7BD79403CF/1 Volume image section/0 A881D567-6CB0-4EEE-8435-2E72D33E45B5')

def find_pe_body_off(fv, base):
    p=base+0x18
    while p < base+0x10000:
        p=(p+3)&~3
        sz=fv[p]|(fv[p+1]<<8)|(fv[p+2]<<16)
        t=fv[p+3]
        if t==0x10:
            return p+4
        if sz<4: break
        p+=sz
    raise Exception('no PE section in file at 0x%x'%base)

d=open(V3,'rb').read()
r=d[0x1000000:0x2000000]
stream=r[LZMA_OFF:LZMA_OFF+LZMA_LEN]
dec=lzma.decompress(stream, format=lzma.FORMAT_AUTO)
assert len(dec)==DEC_SIZE, hex(len(dec))
fv=bytearray(dec[FV_OFF:FV_OFF+FV_SIZE])

for tag,(fn,base) in patches.items():
    pe=open(os.path.join(BIN,'s92-modules',fn),'rb').read()
    if base is None:
        info=open(os.path.join(dump_root,mmap[tag],'info.txt')).read()
        m=re.search(r'Offset:\s*([0-9A-Fa-f]+)h',info)
        base=int(m.group(1),16)
    off=find_pe_body_off(fv,base)
    old=fv[off:off+len(pe)]
    assert len(old)==len(pe), '%s size mismatch %d vs %d'%(tag,len(old),len(pe))
    print('%s: PE body at 0x%x len %d (orig md5 %s)' % (tag,off,len(pe),hashlib.md5(old).hexdigest()[:10]))
    fv[off:off+len(pe)]=pe

dec2=dec[:FV_OFF]+bytes(fv)+dec[FV_OFF+FV_SIZE:]
hdr=struct.pack('<B',0x5d)+struct.pack('<I',0x1000000)+struct.pack('<Q',DEC_SIZE)
filt=[{'id':lzma.FILTER_LZMA1,'dict_size':0x1000000,'lc':3,'lp':0,'pb':2}]
newstream=hdr+lzma.compress(dec2, format=lzma.FORMAT_RAW, filters=filt)
print('new stream', len(newstream), 'orig', LZMA_LEN)
assert len(newstream)<=LZMA_LEN, 'stream grew!'

new_sec_size=0x18+len(newstream)
new_file_size=24+new_sec_size
r2=bytearray(r)
r2[LZMA_OFF:LZMA_OFF+len(newstream)]=newstream
r2[SEC_OFF:SEC_OFF+3]=new_sec_size.to_bytes(3,'little')
r2[FILE_OFF+20:FILE_OFF+23]=new_file_size.to_bytes(3,'little')
old_end=FILE_OFF+0x6C16BA
new_end=FILE_OFF+new_file_size
r2[new_end:old_end]=b'\xff'*(old_end-new_end)
print('file now 0x%x -> 0x%x, gap 0x%x ff-filled' % (old_end,new_end,old_end-new_end))

d2=bytearray(d)
d2[0x1000000:0x2000000]=r2
open(OUT,'wb').write(bytes(d2))
print('wrote', OUT, 'md5', hashlib.md5(bytes(d2)).hexdigest())

rr=bytes(d2[0x1000000+LZMA_OFF:0x1000000+LZMA_OFF+len(newstream)])
decv=lzma.decompress(rr, format=lzma.FORMAT_AUTO)
print('roundtrip OK', hex(len(decv)))
