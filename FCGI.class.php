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

class FCGI 
{
  const NULL_REQUEST_ID   = 0;
  const NULL_REQUEST      = null;
  
  // Values for role component of FCGI_BeginRequestBody
  const RESPONDER         = 1;
  const AUTHORIZER        = 2;
  const FILTER            = 3;

  // FCGI_BeginRequestBody flags
  const KEEP_CONN         = 1;

  // Values for protocolStatus component of FCGI_END_REQUEST
  const REQUEST_COMPLETE  = 0;  // Request completed ok
  const CANT_MPX_CONN     = 1;  // This app cannot multiplex
  const OVERLOADED        = 2;  // Too busy
  const UNKNOWN_ROLE      = 3;  // Role value not known

  // Variable names for FCGI_GET_VALUES / FCGI_GET_VALUES_RESULT records
  const MAX_CONNS         = 'FCGI_MAX_CONNS';
  const MAX_REQS          = 'FCGI_MAX_REQS';
  const MPXS_CONNS        = 'FCGI_MPXS_CONNS';



  // Record types
  const VERSION_1         = 1;
  const BEGIN_REQUEST     = 1;
  const ABORT_REQUEST     = 2;
  const END_REQUEST       = 3;
  const PARAMS            = 4;
  const STDIN             = 5;
  const STDOUT            = 6;
  const STDERR            = 7;
  const DATA              = 8;
  const GET_VALUES        = 9;
  const GET_VALUES_RESULT = 10;

  private $socket;
  private $url;         // Current FCGI server we're connected to
  private $active;      // All requests currently pending
  private $completed;   // Add requests completed
  private $buffer;      // Buffer for multiplex'd read
  private $packet;      // Current request being read

  public function __construct( URL $url )
  {
    $this->socket = null;
    $this->connect($url);
    $this->active = Array();
    $this->completed = Array();
  }

  public function __get($property)
  {
    switch( $property )
    {
      case 'connected':
        //printf("is_resource:%d get_resource_type:%s feof:%d eos:%d\n", $this->socket->handle, get_resource_type($this->socket->handle), feof($this->socket->handle), $this->socket->eos );
        $eof = $this->socket->eos;
  //      if($eof)
  //        $this->kill('connection lost');
        return ! $eof;
      case 'url':
        return $this->url;
      default:
        throw new Exception( get_class($this) . "::$property does not exist");
    }
  }

  public function __set($property, $value)
  {
    throw new Exception( get_class($this) . "::$property cannot be set");
  }

  public static function packet( $type, $request_id, $content = FCGI::NULL_REQUEST )
  {
    $packet = new FCGIPacket( $type, $request_id, $content );
    return $packet->encode();
  }

	public function connect( URL $url ) 
  {
		// Connect to FastCGI server
    $this->url = $url;
		$this->socket = new Stream($url, 1);
    $this->socket->blocking = false;
    $this->socket->write_buffer = 0;
  }

  // Expire incomplete requests (give up on them)
  public function kill($reason = 'request aborted')
  {
    $count = 0;
    foreach( $this->active as $request_id => $request )
    {
      $request->exception = new Exception( get_class($this) . "::kill " . $reason);
      $request->complete = true;
      $this->completed[$request_id] = $request;
      $count++;
    }
    $this->active = Array();
    return $count;
  }

  // Expire incomplete requests (give up on them)
  public function expire($timeout)
  {
    $count = 0;
    foreach( $this->active as $request_id => $request )
    {
      if( $request->timer->elapsed > $timeout )
      {
        $request->exception = new Exception( get_class($this) . "::expire request timed out");
        $this->completed[$request_id] = $request;
        unset($this->active[$request_id]); 
        $count++;
      }
    }
    return $count;
  }

  // Purge these non-claimed requests (response($request_id) never called on them)
  public function purge($timeout)
  {
    $count = 0;
    foreach( $this->completed as $request_id => $request )
    {
      if( $request->timer->elapsed > $timeout )
      {
        unset($this->completed[$request_id]);
        $count++;
      }
    }
    return $count;
  }

  public function active( $request_id )
  {
    return array_key_exists($request_id, $this->active);
  }

  public function completed( $request_id )
  {
    if( array_key_exists( $request_id, $this->completed ) )
      return true;
    elseif( ! $this->active( $request_id) )
      throw new Exception( get_class($this) . "::completed invalid request_id $request_id" );

  }

  public function requests()
  {
    return count($this->active);
  }

  public function getValues()
  {
    $pairs = FCGIPacket::createNVPair( FCGI::MAX_CONNS );
    $pairs .= FCGIPacket::createNVPair( FCGI::MAX_REQS );
    $pairs .= FCGIPacket::createNVPair( FCGI::MPXS_CONNS );

    $packet = new FCGIPacket();
    $packet->type = FCGI::GET_VALUES;
    $packet->request_id = 0;
    $packet->content = $pairs;
		$this->writePacket( $packet->encode() );
    $packet = $this->readPacket();
    $hash = FCGIPacket::decodeNVPairs($packet->content);
    return $hash;
  }

  public function request($uri, $params = Array(), $stdin = null )
  {
		if( ! $this->connected ) 
      throw new Exception( get_class($this) . "::response not connected");

    // Obtain a free request_id
    for( $request_id = rand(1, 65535); $this->active($request_id); $request_id = rand(1, 65535)) ;
    
    $this->active[ $request_id ] = new FCGIResponse( $request_id );

		// Begin session
		$this->writePacket( self::packet( FCGI::BEGIN_REQUEST, $request_id, FCGIPacket::createBeginRequest( FCGI::RESPONDER, FCGI::KEEP_CONN) ) );

		// Build params
		
  	$params_packet = '';
		$params_packet .= FCGIPacket::createNVPair('GATEWAY_INTERFACE', get_class($this) . '/1.0');

		foreach( $params as $key => $value ) 
      if( is_scalar($value) )
        $params_packet .= FCGIPacket::createNVPair($key, $value);

    $params_packet .= FCGIPacket::createNVPair('SCRIPT_FILENAME',   $uri);

		// Send params
		
		$this->writePacket(self::packet( FCGI::PARAMS, $request_id, $params_packet) );
		$this->writePacket( self::packet( FCGI::PARAMS, $request_id ) );
		
		// Build and send stdin flow

		if( $stdin ) 
      $this->writePacket( self::packet(FCGI::STDIN, $request_id, $stdin) );

    $this->writePacket( self::packet(FCGI::STDIN, $request_id) );
    return $request_id;
	}

  private function writePacket($packet)
  {
    for( $i = 0; $i  < 100 ; $i++ )
      if( $this->socket->select(0, Stream::CAN_WRITE) )
        return $this->socket->write($packet);
  }

  private function readPacket()
  {
    if( $this->packet )
    {
      $packet_len = $this->packet->length+$this->packet->padlen+FCGIPacket::HEADER_LEN;
      if( $packet_len > strlen($this->buffer) )
      {
        $data = $this->socket->read( $packet_len - strlen($this->buffer) );
        if( $data === false )
          throw new Exception( get_class($this) . "::response read failed");
        else
          $this->buffer .= $data;
      }

      if( strlen($this->buffer) == $packet_len )
      {
        $packet = FCGIPacket::decode($this->buffer);
        $this->buffer = substr($this->buffer, $packet_len);
        $this->packet = null;
        return $packet;
      }
      else
        return false;

    } else {
      $data = $this->socket->read( FCGIPacket::HEADER_LEN );
      if( $data === false )
        throw new Exception( get_class($this) . "::response read failed");
      else
        $this->buffer .= $data;

      if( strlen($this->buffer) < FCGIPacket::HEADER_LEN )
        return false;

      $this->packet  = FCGIPacket::decode($data);
      $tl = $this->packet->length%FCGIPacket::HEADER_LEN;
      $this->packet->padlen = ( $tl?(FCGIPacket::HEADER_LEN-$tl):0 );
      return false;
    }
  }

  public function poll()
  {
    if( $this->requests() == 0 )
      return 0;

		if( !$this->connected ) 
      throw new Exception( get_class($this) . "::poll not connected");

    if( $this->socket->select(0) )
    {
     // Get the response
     $packet = $this->readPacket();
      if( $packet )
      {
        try {
          $response = $this->processPacket($packet);
        } catch( Exception $e )
        {
          // Check if we're even expecting this packet any more
          if( $this->active($packet->request_id) )
          {
            $request = $this->active[$packet->request_id];
            $request->exception = $e;
            $this->completed[$packet->request_id] = $request;
            unset($this->active[$packet->request_id]);
          }
        }
      }
    }
    return count($this->completed);
  }

	public function response( $request_id )
  {
		// Read response from FastCGI server
    if( $this->completed($request_id) )
    {
      $request = $this->completed[$request_id];
      unset($this->completed[$request_id]);
      return $request;
    }
    elseif($this->active($request_id))
      return false;   // incomplete
    else
      throw new Exception( get_class($this) . "::response request_id $request_id does not exist");
	}

  public function processPacket( FCGIPacket $packet )
  {
    if( $this->active($packet->request_id) )
      $request = $this->active[ $packet->request_id ];
    else
      throw new Exception( get_class($this) . "::processPacket unexpected request_id {$packet->request_id}");

    switch( $packet->type )
    {
      case FCGI::STDOUT:
        $request->stdout .= $packet->content;
        break;

      case FCGI::STDERR:
        $request->stderr .= $packet->content;
        break;

      case FCGI::END_REQUEST:
      {
        $appStatus = FCGIPacket::decodeInteger($packet->content);
        $protocolStatus = ord($packet->content{4});
        switch( $protocolStatus )
        {
          case FCGI::REQUEST_COMPLETE:
            $eoh = strpos($request->stdout, "\r\n\r\n"); 
            if( $eoh )
            {
              $request->headers = substr($request->stdout, 0, $eoh);
              $request->stdout = substr($request->stdout, $eoh + 4);
            }
            $this->completed[$request->request_id] = $request;
            unset($this->active[$request->request_id]);
            return $request;

          case FCGI::CANT_MPX_CONN:
            throw new Exception( get_class($this) . "::response server can't multiplex connection", $protocolStatus);

          case FCGI::OVERLOADED:
            throw new Exception( get_class($this) . "::response server overloaded", $protocolStatus);

          case FCGI::UNKNOWN_ROLE:
            throw new Exception( get_class($this) . "::response server replied unknown role", $protocolStatus);

          default:
            throw new Exception( get_class($this) . "::response unknown protocol status code $protocolStatus", $protocolStatus);
        }
        break;
      }

      default:
        throw new Exception( get_class($this) . "::response unknown protocol type {$packet->type}");
    }
    return false;

  }
	
	function close() 
  {
		$this->socket->close();
	}

}
/*
// Usage example:
include '../../batch/config.php';
$timer = new Timer();
$timer->start;
$fcgi = new FCGI( new URL('tcp://localhost:1223') );
//print_r($fcgi->getValues());
$requets = Array();
for( $i = 0; $i < 100 ; $i++ )
  $requests[] = $fcgi->request( svConfig::get('app_path_bin_dataserver'), Array( 'PING' => Time::seconds() ) );
while(count($requests))
{
  if( $fcgi->poll() )
  {
    foreach($requests as $index => $request_id )
    {
      $response = $fcgi->response($request_id);
      if( $response )
      {
        printf("[%s]\n", $response->stdout);
        unset($requests[$index]);
        printf("%.4f elapsed\n", $timer->elapsed);
      }
    }
  }
}
*/

?>
