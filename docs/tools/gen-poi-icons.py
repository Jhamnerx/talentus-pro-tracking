# Generador del catalogo de iconos de POI (public/images/map_icons/poi/POI_NN.png).
#
# Uso (desde cualquier carpeta):
#   npm i lucide-static@0.544.0 --prefix <carpeta>   # pone los SVG en <carpeta>/node_modules/lucide-static/icons
#   python docs/tools/gen-poi-icons.py public/images/map_icons/poi
# Ajustar ICONS y MAGICK a la ruta local. Cambiar un icono = editar la tabla M
# (lucide, categoria, badge) y volver a ejecutar; los PNG son de 64 px y la
# tabla map_icons los dibuja a 32x32.

import os, re, subprocess, sys
S = os.path.dirname(os.path.abspath(__file__))
ICONS = os.path.join(S, 'lucide/node_modules/lucide-static/icons')
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(S, 'poi_out')
os.makedirs(OUT, exist_ok=True)
MAGICK = r"C:\Program Files\ImageMagick-7.1.2-Q16-HDRI\magick.exe"
C = {'civic': '#2563eb', 'med': '#dc2626', 'food': '#ea580c', 'shop': '#7c3aed',
     'home': '#16a34a', 'tool': '#475569', 'office': '#0d9488', 'misc': '#4f46e5'}
# POI_NN: (lucide, categoria, badge)
M = {
 '03': ('landmark', 'civic', None), '05': ('landmark', 'civic', 'star'), '07': ('landmark', 'civic', 'check'), '09': ('landmark', 'civic', 'x'),
 '100': ('sparkles', 'tool', None), '101': ('search', 'tool', None),
 '11': ('store', 'shop', None), '13': ('store', 'shop', 'star'), '15': ('store', 'shop', 'check'), '17': ('shopping-bag', 'shop', None),
 '112': ('weight', 'shop', None),
 '113': ('utensils', 'food', None), '114': ('utensils-crossed', 'food', None), '115': ('sandwich', 'food', None), '116': ('chef-hat', 'food', None),
 '117': ('coffee', 'food', None), '119': ('ice-cream-cone', 'food', None), '120': ('wine', 'food', None), '121': ('hamburger', 'food', None), '122': ('lollipop', 'food', None),
 '134': ('brush-cleaning', 'tool', None), '135': ('toilet', 'tool', None), '136': ('ruler', 'tool', None), '137': ('fire-extinguisher', 'tool', None),
 '138': ('paintbrush', 'tool', None), '139': ('zoom-in', 'tool', None), '140': ('power', 'tool', None), '141': ('brick-wall', 'tool', None),
 '142': ('wrench', 'tool', None), '143': ('blocks', 'office', None),
 '154': ('package', 'home', None), '155': ('fan', 'home', None), '156': ('sofa', 'home', None), '157': ('bed', 'home', None), '158': ('lamp-desk', 'home', None),
 '159': ('cctv', 'misc', None), '160': ('shovel', 'home', None), '161': ('flower', 'home', None), '162': ('sprout', 'home', None), '163': ('fence', 'home', None),
 '174': ('bandage', 'med', None), '176': ('bandage', 'med', 'plus'), '178': ('pill-bottle', 'med', None), '179': ('scissors', 'med', None), '180': ('pill', 'med', None),
 '181': ('soap-dispenser-droplet', 'med', None), '182': ('briefcase-medical', 'med', None), '183': ('syringe', 'med', None),
 '184': ('highlighter', 'office', None), '185': ('paintbrush-vertical', 'office', None), '196': ('pen', 'office', None), '197': ('watch', 'shop', None),
 '198': ('gem', 'shop', None), '199': ('ship-wheel', 'tool', None), '200': ('cog', 'tool', None), '201': ('battery-charging', 'tool', None),
 '203': ('pen-line', 'office', None), '204': ('notebook', 'office', None), '205': ('book', 'office', None), '206': ('graduation-cap', 'office', None),
 '217': ('swords', 'misc', None), '218': ('shield', 'misc', None), '219': ('anchor', 'misc', None), '220': ('key-round', 'misc', None),
 '221': ('slice', 'misc', None), '223': ('target', 'misc', None), '224': ('crosshair', 'misc', None),
 '28': ('house', 'home', None), '30': ('house', 'home', 'plus'), '31': ('house', 'home', 'minus'), '32': ('house', 'home', 'star'), '33': ('house', 'home', 'check'), '34': ('house', 'home', 'x'),
 '42': ('warehouse', 'home', None), '43': ('warehouse', 'home', 'star'), '44': ('warehouse', 'home', 'check'), '45': ('warehouse', 'home', 'x'),
 '46': ('trees', 'home', None), '47': ('church', 'misc', None), '48': ('caravan', 'home', None), '49': ('hotel', 'home', None),
 '51': ('wind', 'misc', None), '53': ('radio-tower', 'misc', None), '65': ('castle', 'civic', None), '67': ('school', 'civic', None), '68': ('hospital', 'med', None),
 '69': ('factory', 'civic', None), '70': ('building', 'civic', None), '71': ('building-2', 'civic', None), '72': ('container', 'civic', None), '73': ('hard-hat', 'civic', None),
 '74': ('milestone', 'civic', None), '75': ('droplets', 'misc', None), '86': ('cross', 'misc', None), '87': ('sun', 'home', None),
 '90': ('shirt', 'shop', None), '91': ('tag', 'shop', None), '92': ('spool', 'shop', None), '93': ('shopping-cart', 'shop', None), '94': ('washing-machine', 'shop', None),
 '96': ('vault', 'shop', None), '98': ('bike', 'shop', None), '99': ('disc', 'shop', None),
}
BADGE = {
 'star': ('#f59e0b', '<path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4 6.1 20.5l1.2-6.5L2.5 9.4l6.6-.9z" fill="#fff" stroke="none"/>'),
 'check': ('#16a34a', '<path d="M5 12.5l4.5 4.5L19 7.5"/>'),
 'x': ('#dc2626', '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>'),
 'plus': ('#0ea5e9', '<path d="M12 5v14M5 12h14"/>'),
 'minus': ('#f97316', '<path d="M5 12h14"/>'),
}

def body(name):
    p = os.path.join(ICONS, name + '.svg')
    if not os.path.isfile(p):
        return None
    s = open(p, encoding='utf-8').read()
    return re.search(r'<svg\b[^>]*>\s*(.*?)\s*</svg>', s, re.S).group(1)

missing = [(n, M[n][0]) for n in M if body(M[n][0]) is None]
if missing:
    print('MISSING:', missing)
    sys.exit(1)

for n, (l, cat, badge) in sorted(M.items()):
    col = C[cat]
    g = body(l)
    svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">',
           '<circle cx="32" cy="33.5" r="28.5" fill="#000" fill-opacity="0.16"/>',
           '<circle cx="32" cy="31.5" r="28" fill="%s"/>' % col,
           '<circle cx="32" cy="31.5" r="28" fill="none" stroke="#fff" stroke-width="3"/>',
           '<g transform="translate(16.5 16) scale(1.2917)" fill="none" stroke="#fff" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">%s</g>' % g]
    if badge:
        bc, bp = BADGE[badge]
        svg.append('<circle cx="49" cy="48" r="11" fill="%s" stroke="#fff" stroke-width="2.5"/>' % bc)
        svg.append('<g transform="translate(41.5 40.5) scale(0.625)" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">%s</g>' % bp)
    svg.append('</svg>')
    tmp = os.path.join(OUT, 'POI_%s.svg' % n)
    open(tmp, 'w', encoding='utf-8').write(''.join(svg))
    subprocess.run([MAGICK, '-background', 'none', '-density', '288', tmp, '-resize', '64x64', os.path.join(OUT, 'POI_%s.png' % n)], check=True)
    os.remove(tmp)
print('generated', len(M))
