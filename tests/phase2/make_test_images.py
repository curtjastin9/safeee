from PIL import Image, ImageDraw, ImageFont
import os
d=os.path.dirname(os.path.abspath(__file__))
def font(sz):
    for f in ['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf','/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf']:
        if os.path.exists(f): return ImageFont.truetype(f,sz)
    return ImageFont.load_default()
def card(name, lines, size=(1200,760), exif_sw=None, fmt='JPEG'):
    im=Image.new('RGB',size,(238,232,215)); dr=ImageDraw.Draw(im)
    dr.rectangle([0,0,size[0],110],fill=(30,60,140)); dr.text((40,30),lines[0],font=font(46),fill=(255,255,255))
    y=160
    for l in lines[1:]:
        dr.text((60,y),l,font=font(42),fill=(15,15,25)); y+=80
    kw={}
    if exif_sw:
        ex=Image.Exif(); ex[0x0131]=exif_sw; ex[0x010F]='Apple'; kw['exif']=ex
    path=os.path.join(d,name)
    if fmt=='JPEG': im.save(path,'JPEG',quality=92,**kw)
    else: im.save(path,fmt)
    return path
card('dl.jpg',['REPUBLIC OF THE PHILIPPINES - DRIVER\'S LICENSE','Last Name, First Name, Middle Name','DELA CRUZ, JUAN MIGUEL','License No.  N01-23-456789','Expiration Date  2030-11-17','Birth Date  1990-01-01'])
card('dl_other_name.jpg',['REPUBLIC OF THE PHILIPPINES - DRIVER\'S LICENSE','Last Name, First Name, Middle Name','SANTOS, MARIA CLARA','License No.  N01-23-456789','Expiration Date  2030-11-17'])
card('edited.jpg',['PASSPORT','DELA CRUZ JUAN'],exif_sw='Adobe Photoshop 25.0')
card('shot.png',['DRIVER\'S LICENSE','DELA CRUZ JUAN'],size=(1080,1920),fmt='PNG')
print('ok')
