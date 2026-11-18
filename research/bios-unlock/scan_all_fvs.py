import struct
import uefi_firmware

RGN='/Users/user/Documents/Repositories/tscrub/research/bios-unlock/BINs/CND14004KD_BIOS_region.rgn'
d=open(RGN,'rb').read()
uc=bytes.fromhex('ff 87 66 f6 f3 8c 19 4a b4 ac f5 f0 b7 8e 4d 18')
ucu16='UserCred'.encode('utf-16-le')
smc=bytes.fromhex('31 76 4e fd 58 35 24 4c ad d3 c4 7e be ab 92 67')

def walk(o, out=None):
    if out is None: out=[]
    dd=getattr(o,'data',None)
    if isinstance(dd,bytes) and dd:
        out.append((type(o).__name__,getattr(o,'name',None),dd))
    for c in getattr(o,'objects',None) or []:
        walk(c,out)
    return out

fvs=[('372B56DF',0x3B000,0x11000),
     ('8C8CE578',0x925000,0xA0000),
     ('1B5C27FE',0xA3E000,0x34000),
     ('52F1AFB6',0xA72000,0x8E000),
     ('7BEBD21A',0xB00000,0x5000),
     ('B73FE497',0xB05000,0x187000),
     ('8C8CE578b',0xC97000,0xA0000)]
for name,base,size in fvs:
    fv=d[base:base+size]
    try:
        fs=uefi_firmware.AutoParser(fv).parse()
    except Exception as e:
        print('FV',name,'parse fail',e); continue
    print('==== FV',name,'@0x%x'%base)
    nfiles=0
    for tag,nm,dd in walk(fs):
        hit=[]
        if uc in dd or ucu16 in dd: hit.append('UC')
        if smc in dd: hit.append('SMC')
        if tag=='FirmwareFile': nfiles+=1
        if hit:
            gs=''
            if hasattr(nm,'value'): gs=str(nm.value)
            elif isinstance(nm,str): gs=nm
            print('  %-16s %-24s len=%-8d %s' % (tag,gs[:24],len(dd),hit))
    print('  files: %d' % nfiles)
