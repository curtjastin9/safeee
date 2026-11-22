# Builds the 3 test images from scikit-image's public-domain NASA portrait. pip install scikit-image pillow
from skimage import data
from PIL import Image
im = Image.fromarray(data.astronaut())
card = Image.new('RGB', (1200, 760), (238, 232, 215)); card.paste(im.resize((300, 300)), (60, 200)); card.save('id_with_face.jpg', quality=92)
im.crop((60, 0, 460, 440)).resize((400, 440)).save('selfie_like.jpg', quality=92)
Image.new('RGB', (1200, 760), (238, 232, 215)).save('id_no_face.jpg')
