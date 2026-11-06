#!/usr/bin/env python3
"""Build uploadable Joomla packages from the checked-in plugin sources only."""
import io
from pathlib import Path
import xml.etree.ElementTree as ET
from zipfile import ZipFile, ZIP_DEFLATED, ZipInfo

ROOT = Path(__file__).resolve().parents[1]

def archive(files):
    output = io.BytesIO()
    with ZipFile(output, 'w', compression=ZIP_DEFLATED) as zipped:
        for name, data in sorted(files.items()):
            info = ZipInfo(name, (2026, 9, 28, 0, 0, 0))
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            zipped.writestr(info, data)
    return output.getvalue()

def plugin_archive(group, element):
    source = ROOT / 'plugins' / group / element
    manifest = source / (element + '.xml')
    xml = ET.parse(manifest).getroot()
    if xml.get('type') != 'plugin' or xml.get('group') != group:
        raise ValueError('Plugin identity mismatch: ' + str(manifest))
    entries = {manifest.name: manifest.read_bytes()}
    for node in xml.findall('./files/*'):
        path = source / node.text.strip()
        if not path.exists() or path.is_symlink() or not path.resolve().is_relative_to(source.resolve()):
            raise ValueError('Missing or unsafe manifest entry: ' + str(path))
        children = sorted(path.rglob('*')) if path.is_dir() else [path]
        for child in children:
            if child.is_symlink():
                raise ValueError('Symlinks cannot be packaged: ' + str(child))
            if child.is_file():
                entries[child.relative_to(source).as_posix()] = child.read_bytes()
    for node in xml.findall('./languages/language'):
        name = str(Path(xml.find('languages').get('folder', '')) / node.text.strip())
        if name not in entries:
            raise ValueError('Language missing from archive: ' + name)
    return archive(entries), xml.findtext('version')

def build(platform):
    source = ROOT / 'packages' / platform
    name = 'pkg_teya_' + platform
    manifest = source / (name + '.xml')
    xml = ET.parse(manifest).getroot()
    files = {manifest.name: manifest.read_bytes(), 'script.php': (source / 'script.php').read_bytes()}
    for extra_name in ('LICENSE.txt','README.txt'):
        if (source / extra_name).is_file():
            files[extra_name] = (source / extra_name).read_bytes()
    for node in xml.findall('./files/file'):
        data, version = plugin_archive(node.get('group'), node.get('id'))
        files['packages/' + node.text.strip()] = data
    # The package release follows its manifest; child plugins retain their own versions.
    version = xml.findtext('version')
    output = ROOT / 'dist' / (name + '-' + version + '.zip')
    output.parent.mkdir(exist_ok=True)
    output.write_bytes(archive(files))
    with ZipFile(output) as outer:
        assert outer.testzip() is None
        assert set(outer.namelist()) == set(files), 'Unexpected package entries'
        for node in xml.findall('./files/file'):
            with ZipFile(io.BytesIO(outer.read('packages/' + node.text.strip()))) as child:
                assert child.testzip() is None
                assert node.get('id') + '.xml' in child.namelist()
    print(str(output.relative_to(ROOT)) + ' (' + str(output.stat().st_size) + ' bytes)')

if __name__ == '__main__':
    build('virtuemart')
