<?php
/* FCGIServer.class.php - Implementation of the FastCGI Protocol in PHP
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

// include '../../batch/config.php';

class FCGIServer extends FCGI
{
	public function __construct( Stream $socket ) 
  {
		$this->socket = $socket;
    $this->socket->blocking     = false;  // Disable blocking
    $this->socket->write_buffer = 0;      // Disable write buffer
  }
  public function abort($request_id, $reason = 'requested')
  {
    if(!$this->active($request_id))
      throw new Exception( get_class($this) . "::abort $request_id not found");
    unset($this->active[$request_id]);
    return true;
  }

  // Expire incomplete requests (give up on them)
  public function kill($reason = 'request aborted')
  {
    $count = 0;
    foreach( $this->active as $request_id => $request )
    {
      $this->abort($request_id, $reason);
      $count++;
    }
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
        $this->abort($request_id, 'request timed out');
        $count++;
      }
    }
    return $count;
  }

  public function poll()
  {
    //print Time::seconds() . "\n";

    $status = $this->socket->select(0, Stream::CAN_READ|Stream::CAN_WRITE);

    if( $status & Stream::CAN_READ )
      $this->canRead();

    if( $status & Stream::CAN_WRITE )
      $this->canWrite();

    $this->decodePackets();
  }

  public function getValues()
  {
    throw new Exception( get_class($this) . "::getValues not implemented");
  }

  // Extend this method. This should do something.
  public function processRequest(FCGIRequest $request)
  {
    // print "Got request\n";
    $response = new FCGIResponse($request->request_id);
    $response->stdout = 'STDOUT works';
    $response->stderr = 'STDERR works';
    $response->params = Array('foo' => 'bar');
    return $response;
  }


  // Calls process
  public function executeRequest(FCGIRequest $request)
  {
    // Request complete
    try {
      $this->completed[$request->request_id] = $request;
      unset($this->active[$request->request_id]);
      $response = $this->processRequest($request);
      if( ! $response instanceof FCGIResponse )
        throw new Exception( get_class($this) . "::executeRequest invalid response");
    } catch( Exception $e )
    {
      $response = new FCGIResponse($request->request_id);
      $response->exeception = $e;
      $response->stderr = $e->getMessage();
      $response->stdin = '';
    }
    //print_r($response);
    $this->writePacket( $this->buildResponse($response) );
  }

  public function buildResponse(FCGIResponse $response)
  {
    $data = '';
    $params = FCGIPacket::encodeNVPairs($response->params);

    $data .= self::packet( FCGI::PARAMS, $response->request_id, $params );
    $data .= self::packet( FCGI::PARAMS, $response->request_id ) ;

    // Build and send stdin flow

    if( $response->stdout )
      $data .= self::packet(FCGI::STDOUT, $response->request_id, $response->stdout);
    $data .= self::packet(FCGI::STDOUT, $response->request_id);

    if( $response->stderr )
      $data .= self::packet(FCGI::STDERR, $response->request_id, $response->stderr);
    $data .= self::packet(FCGI::STDERR, $response->request_id);

    $data .=  self::packet( FCGI::END_REQUEST, $response->request_id, FCGIPacket::createEndRequest($response->appStatus, $response->protocolStatus) );
    return $data;
  }

  // 
  // All receive packet call backs
  //

  public function recvStdin(FCGIPacket $packet)
  {
    // print "Got STDIN {$packet->request_id}\n";
    $request = $this->getActiveRequest($packet->request_id);
    if( $packet->content == FCGI::NULL_REQUEST )
    {
      $this->executeRequest($request);
    }
    else 
    {
      $request->stdin .= $packet->content;
    }
    return true;
  }

  public function recvStdout(FCGIPacket $packet)
  {
    throw new Exception( get_class($this) . "::recvStdout not implemented");
  }

  public function recvStderr(FCGIPacket $packet)
  {
    throw new Exception( get_class($this) . "::recvStdout not implemented");
  }

  public function recvBeginRequest(FCGIPacket $packet)
  {
    // print "Got BEGIN_REQUEST {$packet->request_id}\n";
    $request_id = $packet->request_id;
    $this->active[ $request_id ] = new FCGIRequest( $request_id );
    return true;
  }

  public function recvAbortRequest(FCGIPacket $packet)
  {
    $this->abort($packet->request_id);
    return true;
  }

  public function recvEndrequest(FCGIPacket $packet)
  {
    throw new Exception( get_class($this) . "::recvEndRequest not implemented");
  }

  public function recvData(FCGIPacket $packet)
  {
    throw new Exception( get_class($this) . "::recvData not implemented");
  }

  public function recvParams(FCGIPacket $packet)
  {
    // print "Got PARAMS {$packet->request_id}\n";
    $request = $this->getActiveRequest($packet->request_id);
    $hash = FCGIPacket::decodeNVPairs($packet->content);
    $request->params = array_merge($request->params, $hash);
    //print_r($request->params);
    return true;
  }

  public function recvGetValues(FCGIPacket $packet)
  {
    throw new Exception( get_class($this) . "::recvGetValues not implemented");
  }

  public function recvGetValuesResult(FCGIPacket $packet)
  {
    throw new Exception( get_class($this) . "::recvGetValuesResult not implemented");
  }
}

/*

// Usage Exampe:
srand(2);
$socket = new Stream();
$socket->bind('tcp://localhost:1111');
$fcgi = new FCGI(new URL('tcp://localhost:1111'));
$client = $socket->accept();
$server = new FCGIServer($client);
$timer = new Timer();
$timer->start;
$request_id = $fcgi->request('/tmp/t.php', Array( 'asd' => 123 ), 'this post' );
while(1)
{
  $server->poll();
  $fcgi->poll();
  //usleep(1000);
  if( $response = $fcgi->response($request_id) )
  {
    print_r($response);
    printf("%.4f elapsed\n", $timer->elapsed);
    break;
  }
}
*/

?>
