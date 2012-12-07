<?php
require_once 'util.php';
require_once 'ASM.php';
require_once 'Data.php';

class Model {
	private $rominfo;
	private $map;
	private $romfile;
	
	private static function file2snes($offset) {
		return 0xc00000 + ($offset & 0x3fffff);
	}

	private static function snes2file($addr) {
		return $addr & 0x3fffff;
	}
	
	public function __construct($yamlfile, $romfile) {
		$ebyaml = yaml_parse_file($yamlfile, -1);
		$this->rominfo = $ebyaml[0];
		$this->map = $ebyaml[1];
		$this->romfile = $romfile;
		$replacements = &$this->rominfo['texttables']['standardtext']['replacements'];
		unset($replacements[0x00]);
		unset($replacements[0x03]);
		$replacements[0x52] = '"';
		$replacements[0x52] = '#';
		$replacements[0x55] = '%';
		$replacements[0x56] = '&';
		$replacements[0x5b] = '+';
		$replacements[0x5f] = '/';
		$replacements[0x6a] = ':';
		$replacements[0x6b] = ';';
		$replacements[0x6c] = '<';
		$replacements[0x6d] = '=';
		$replacements[0x6e] = '>';
		$replacements[0x8b] = 'α';
		$replacements[0x8c] = 'β';
		$replacements[0x8d] = 'γ';
		$replacements[0x8e] = 'Σ';
		$replacements[0x90] = '`';
		$replacements[0xab] = '{';
		$replacements[0xac] = '|';
		$replacements[0xad] = '}';
		$replacements[0xae] = '~';
		$replacements[0xaf] = '◯';
		$staffreplacements = &$this->rominfo['texttables']['stafftext']['replacements'];
		unset($staffreplacements[0x00]);
		$staffreplacements[0x41] = '!';
		$staffreplacements[0x43] = '#';
		$staffreplacements[0x4c] = ',';
		$staffreplacements[0x4d] = '-';
		$staffreplacements[0x4e] = '.';
		$staffreplacements[0x4f] = '/';
		$staffreplacements[0x58] = 'j';
		$staffreplacements[0x60] = '0';
		$staffreplacements[0x61] = '1';
		$staffreplacements[0x62] = '2';
		$staffreplacements[0x63] = '3';
		$staffreplacements[0x64] = '4';
		$staffreplacements[0x65] = '5';
		$staffreplacements[0x66] = '6';
		$staffreplacements[0x67] = '7';
		$staffreplacements[0x68] = '8';
		$staffreplacements[0x69] = '9';
		$staffreplacements[0x6a] = 'q';
		$staffreplacements[0x7e] = 'z';
		$staffreplacements[0x80] = '_';
		$staffreplacements[0xad] = ';';
		$staffreplacements[0xcc] = '|';
		$staffreplacements[0xce] = '~';
		$staffreplacements[0xcf] = '◯';
		StandardTextEntry::$textTable = $this->rominfo['texttables']['standardtext'];
		StaffTextEntry::$textTable = $this->rominfo['texttables']['stafftext'];
		$ntdarr = &$this->map['NINTENDO_ARRANGEMENT'];
		$ntdarr['compressed'] = true;
		$ntdarr['entries'] = array([ 'name' => 'Arrangement', 'size' => 512 ]);
		$ntdgrf = &$this->map['NINTENDO_GRAPHICS'];
		$ntdgrf['compressed'] = true;
		$ntdgrf['entries'] = array([ 'name' => 'Tile', 'type' => 'tile', 'size' => 32, 'bpp' => 2, 'palette' => 0xe1558f ]);
		$ntdpal = &$this->map['NINTENDO_PALETTE'];
		$ntdpal['compressed'] = true;
		$ntdpal['entries'] = array([ 'name' => 'Palette', 'type' => 'palette', 'size' => 8]);
	}
	
	public function getRomInfo() {
		return $this->rominfo;
	}
	
	public function getFromName($name) {
		$address = dechex($this->map[$name]['offset']);
		return $this->getFromAddress($address);
	}
	
	public function getFromAddress($address) {
		$desc = $this->map[hexdec($address)];
		$rom = fopen($this->romfile, 'rb');
		fseek($rom, static::snes2file(intval($desc['offset'])));
		$size = intval($desc['size']);
		$data = fread($rom, $size);
		fclose($rom);
		$name = isset_or($desc['name'], '');
		$description = isset_or($desc['description'], '');
		switch ($desc['type']) {
		case 'assembly':
			$asm = array_map(function($byte) { return ord($byte); }, str_split($data));
			$labels = isset_or($desc['labels'], []);
			$args = isset_or($desc['arguments'], []);
			$localvars = isset_or($desc['localvars'], []);
			return new ASM($name, $description, $size, $desc['offset'], $asm, $labels, $args, $localvars);
			break;
		case 'data':
			$data = array_map(function($byte) { return ord($byte); }, str_split($data));
			$size = isset_or($desc['size']);
			$terminator = isset_or($desc['terminator']);
			$dataObj = new Data($name, $description, $size, $terminator, $desc['offset']);
			if (isset($desc['compressed']) && $desc['compressed'] === true) {
				$tmp = array_fill(0, 4096, 0);
				decomp($data, $tmp);
				$data = $tmp;
				$size = count($data);
			}
			if (isset($desc['entries'])) {
				$entries = $desc['entries'];
				for ($i = 0; $i < $size;) {
					foreach ($entries as $entry) {
						$entryname = $entry['name'];
						$entrydata = [];
						$entrysize = null;
						if (isset($entry['size'])) {
							$entrysize = $entry['size'];
							$entrydata = array_slice($data, $i, $entrysize);
						}
						$entryterm = null;
						if (isset($entry['terminator'])) {
							$entryterm = $entry['terminator'];
							$entrydata = $data;
						}
						$entrytype = isset_or($entry['type']);
						$entryObj = null;
						switch ($entrytype) {
						case 'standardtext':
							$entryObj = new StandardTextEntry($entryname, $entrysize, $entryterm, $entrydata);
							break;
						case 'stafftext':
							$entryObj = new StaffTextEntry($entryname, $entrysize, $entryterm, $entrydata);
							break;
						case 'pointer':
							$entryObj = new PointerEntry($entryname, $entrysize, array_reverse($entrydata));
							break;
						case 'hilomid pointer':
							$entryObj = new PointerEntry($entryname, $entrysize, [$entrydata[0], $entrydata[2], $entrydata[1]]);
							break;
						case 'hexint':
							$entryObj = new HexIntEntry($entryname, $entrysize, array_reverse($entrydata));
							break;
						case 'int':
							$entryObj = new IntEntry($entryname, $entrysize, $entrydata);
							break;
						case 'bytearray':
							$entryObj = new ByteArrayEntry($entryname, $entrysize, $entrydata);
							break;
						case 'bitfield':
							$entryObj = new BitFieldEntry($entryname, $entrysize, $entrydata, $entry['bitvalues']);
							break;
						case 'tile':
							if (isset($entry['palette'])) {
								$paladdress = $entry['palette'];
								$offset = 0;
								$palsize = pow(2, $entry['bpp']) * 2;
								while (!isset($this->map[$paladdress])) {
									$paladdress -= $palsize;
									++$offset;
								}
								$entryObj = new TileEntry($entryname, $entrysize, $entrydata, $entry['bpp'], $this->getFromAddress(hexbyte($paladdress, 6))->getEntries()[$offset]);
							}
							else {
								$entryObj = new TileEntry($entryname, $entrysize, $entrydata, $entry['bpp']);
							}
							break;
						case 'palette':
							$entryObj = new PaletteEntry($entryname, $entrysize, $entrydata);
							break;
						default:
							$entryObj = new DataEntry($entryname, $entrysize, $entryterm, $entrydata);
						}
						$dataObj->addEntry($entryObj);
						if ($entrysize === null) $entrysize = count($entryObj->getData());
						$i += $entrysize;
					}
				}
			}
			else {
				$entrysize = null;
				if (isset($entry['size'])) {
					$entrysize = $entry['size'];
				}
				$entryterm = null;
				if (isset($entry['terminator'])) {
					$entryterm = $entry['terminator'];
				}
				$entryObj = new DataEntry('Data', $entrysize, $entryterm, $data);
				$dataObj->addEntry($entryObj);
			}
			return $dataObj;
		case 'empty':
			return null;
		}
	}
	
	public function getRomMap() {
		$ret = [];
		foreach ($this->map as $key => $value) {
			$offset = $value['offset'];
			if (is_string($key) && ($offset < 0x7e0000 || $offset > 0x7fffff)) {
				$ret[$key] = $value;
			}
		}
		return $ret;
	}
	
	public function getRamMap() {
		$ret = [];
		foreach ($this->map as $key => $value) {
			$offset = $value['offset'];
			if (is_string($key) && $offset >= 0x7e0000 && $offset <= 0x7fffff) {
				$ret[$key] = $value;
			}
		}
		return $ret;
	}
}
?>
