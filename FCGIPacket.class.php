<?php
/* FCGI.class.php - Implementation of the FastCGI Protocol in PHP
 * Copyright (C) 2007 Erik Osterman <e@osterman.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/* File Authors:
 *   Erik Osterman <e@osterman.com>
 */

class FCGIPacket
{
  const HEADER_LEN  = 8;

  private $version;     // Identifies the FastCGI protocol version
  private $type;        // Identifies the FastCGI record type, i.e. the general function that the record performs.
  private $request_id;  // Identifies the FastCGI request to which the record belongs
  private $length;      // The number of bytes in the content component of the record
  private $padlen;      // The number of bytes in the padlen component of the record
  private $reserved;    // 
  private $content;     // Between 0 and 65535 bytes of data, interpreted according to the record type


  // FYI: Management records have a requestId value of zero, also called the null request ID. Application records have a nonzero requestId

  public function __construct()
  {
    $this->version    = null;
    $this->type       = null;
    $this->request_id = rand(1, 256*256 - 1);
    $this->length     = 0;
    $this->padlen     = 0;
    $this->reserved   = 0;
    $this->content    = null;

    $args = func_get_args();
    switch( count($args) )
    {
      case 0:
        // do nothing
        break;
      case 1:
        $this->__set('type',    $args[0]);
        break;
      case 2:
        $this->__set('type',    $args[0]);
        $this->__set('content', $args[1]);
        break;

      case 3:
        $this->__set('type',       $args[0]);
        $this->__set('request_id', $args[1]);
        $this->__set('content',    $args[2]);
        break;

      case 4:
        $this->__set('type',       $args[0]);
        $this->__set('request_id', $args[1]);
        $this->__set('length',     $args[2]);
        $this->__set('content',    $args[3]);
        break;

      default:
        throw new Exception( get_class($this) . "::__construct unrecognized argument signature");
    }

  }

  public function __toString()
  {
    return "version: {$this->version} type: {$this->type} request_id: {$this->request_id} length: {$this->length} content: {$this->content}";
  }

  public function __get($property)
  {
    if( property_exists($this, $property) )
      return $this->$property;
    else
      throw new Exception( get_class($this) . "::$property does not exist");
  }

  public function __set($property, $value)
  {
    if( property_exists($this, $property) )
      if( is_scalar($value) || $value === null )
        return $this->$property = $value;
      else
        throw new Exception( get_class($this) . "::$property must be scalar");
      throw new Exception( get_class($this) . "::$property cannot be set");
  }

  public static function encodeShort( $var )
  {
    return chr((int)($var/256)).chr($var%256);
  }

  public static function decodeShort( $var )
  {
    return (ord($var{0}) << 8)+ord($var{1});
  }

  public static function encodeInteger( $var)
  {
    if( $var < 128 )
      return chr($var);
    else
      return chr(($var >> 24) | 0x80) . chr(($var >> 16) & 0xFF) . chr(($var >> 8) & 0xFF) . chr($var & 0xFF);
  }

  public static function decodeInteger( $var )
  {
      return ((ord($var{0}) << 24) & 0x80 )
           + ((ord($var{1}) << 16) )
           + ((ord($var{2}) << 8))
           + (ord($var{3})) ;
  }

	public function encode()
  {
		$this->length = BinaryString::len($this->content);
		$packet  = chr(FCGI::VERSION_1);
		$packet .= chr($this->type);
		$packet .= self::encodeShort($this->request_id);// Request id = 1
		$packet .= self::encodeShort($this->length);    // Content length
		$packet .= chr($this->padlen);                 // Padding length
    $packet .= chr($this->reserved);                // Reserved
		$packet .= $this->content;
		return($packet);
	}

  public static function decode( $data )
  {
    if( BinaryString::len($data) < 8 )
      throw new Exception( __CLASS__ . "::decode data len too short (" . BinaryString::len($data)  .")" );
    $packet = new FCGIPacket();
    $packet->version    = ord($data{0});
		$packet->type       = ord($data{1});
    $packet->request_id = self::decodeShort( $data{2} . $data{3} );
    $packet->length     = self::decodeShort( $data{4} . $data{5} );
    $packet->padlen     = ord($data{6});
    $packet->reserved   = ord($data{7});
		$packet->content    = substr($data, FCGIPacket::HEADER_LEN, $packet->length);

    return $packet;	
	}

  public static function decodeNVPairs($data) 
  {
    $hash = Array();

    while(strlen($data))
    {
      // FCGI_NameValuePair11;
      if( ord($data{0}) >> 7 == 0 && $data{0} >> 7 == 0 )
      {
        $nlen = ord($data{0});
        $vlen = ord($data{1});
        $name = substr($data, 2, $nlen);
        $value = substr($data, 2 + $nlen, $vlen);
        $data = substr($data, 2 + $nlen + $vlen );
       } 

      // FCGI_NameValuePair14
      elseif( ord($data{0}) >> 7 == 0 && ord($data{1}) >> 7 == 1 )
      {
        $nlen = ord($data{0});
        $vlen = self::decodeInteger( $data{1} . $data{2} . $data{3} . $data{4} ) ;
        $name = substr($data, 5, $nlen);
        $value = substr($data, 5 + $nlen, $vlen);
        $data = substr($data, 5 + $nlen + $vlen );
      } 
      
      // FCGI_NameValuePair41
      elseif( ord($data{0}) >> 7 == 1 && ord($data{4}) >> 7 == 0 )
      {
        $nlen = self::decodeInteger( $data{0} . $data{1} . $data{2} . $data{3} ) ;
        $vlen = ord($data{4});
        $name = substr($data, 5, $nlen);
        $value = substr($data, 5 + $nlen, $vlen);
        $data = substr($data, 5 + $nlen + $vlen );
      } 
      
      // FCGI_NameValuePair44
      elseif( ord($data{0}) >> 7 == 1 && ord($data{4}) >> 7 == 1 )
      {
        $nlen = self::decodeInteger( $data{0} . $data{1} . $data{2} . $data{3} ) ;
        $vlen = self::decodeInteger( $data{4} . $data{5} . $data{6} . $data{7} ) ;
        $name = substr($data, FCGIPacket::HEADER_LEN, $nlen);
        $value = substr($data, FCGIPacket::HEADER_LEN + $nlen, $vlen);
        $data = substr($data, FCGIPacket::HEADER_LEN + $nlen + $vlen );
      }
      
      else throw new Exception( get_class($this) . "::decodeNVPairs unknown byte order");

      if( preg_match('/FCGI_/', $name) )
        $value = ord($value);

      $hash[$name] = $value;
      
    }
    return $hash;

  }

  public static function createNVPair($name, $value = null) 
  {
    if( !is_scalar($name) )
      throw new Exception( __CLASS__ . "::createNVPair name must be a scalar");
    if( !is_scalar($value) && $value !== null )
      throw new Exception( __CLASS__ . "::createNVPair value must be a scalar");

    $nlen = strlen($name);
    $vlen = strlen($value);

    $header = self::encodeInteger($nlen) . self::encodeInteger($vlen);

    return $header . $name . $value;

  } 

  public static function createBeginRequest( $role = FCGI::RESPONDER, $flags = 0 , $reserved = null )
  {
    if( $reserved === null )
      $reserved = chr(0).chr(0).chr(0).chr(0).chr(0);
    if( BinaryString::len($reserved) != 5 )
      throw new Exception( __CLASS__ . "::createBeginRequest expects reserved to be 5 bytes");

    return self::encodeShort( $role ) 
           . chr($flags)
           . $reserved;
  }
}

?>
