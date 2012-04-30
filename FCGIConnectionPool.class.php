<?php
/* FCGIConnectionPool.class.php - Implementation of the FastCGI Protocol in PHP
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

class FCGIConnectionPool
{
  private $index;
  private $pool;
  private $size;
  private $servers;
  private $requests;

  public function __construct( $size )
  {
    $this->size     = $size;
    $this->pool     = Array();
    $this->servers  = Array();
    $this->requests = Array();
    $this->index    = 0;
    for($i = 0; $i < $this->size; $i++)
      $this->pool[$i] = null;
  }

  public function requests()
  {
    return count($this->requests);
  }

  public function add($url)
  {
    array_push($this->servers, $url);
  }

  public function remove($url)
  {
    $count = 0;
    // Remove from server list
    foreach( $this->servers as $index => $server )
    {
      if( $server->url == $url->url )
      {
        unset($this->servers[$index]);
        $count++;
      }
    }
    // Re-index the servers array
    $this->servers = array_values($this->servers);

    // Remove from pool
    foreach( $this->pool as $index => $fcgi )
      if( $fcgi instanceof FCGI )
        if( $fcgi->url == $url->url )
          $this->pool[$index] = null;

    return $count;
  }

  // Returns a random server url
  public function selectServer()
  {
    if( count($this->servers) )
      return $this->servers[ array_rand($this->servers) ]; 
    else
      throw new Exception( get_class($this) . "::selectServer list empty");
  }


  // Returns true if a valid connection
  public function testConnection( FCGI $connection )
  {
    return $connection->connected;
  }

  private function getConnection()
  {
    // Initialize a new fcgi server connection object
    for( $i = 0; $i < 100; $i++ )
    {
      $url = $this->selectServer();
      try {
        $connection = new FCGI( $url );
        if( $this->testConnection($connection) )
          return $connection;
        else
          continue;
      } catch( Exception $e )
      {
        continue;
      }
    }
    throw new Exception( get_class($this) . "::selectConnection unable to establish FCGI connection; all servers have gone away");
  }

  
  public function preallocateConnections()
  {
    for( $i = 0; $i < $this->size; $i++ )
      if( ! $this->pool[$i] instanceof FCGI )
        $this->pool[$i] = $this->getConnection();

    for( $i = 0; $i < $this->size; $i++ )
      if( $this->pool[$i] instanceof FCGI )
      {
        $this->pool[$i]->close();
        $this->pool[$i] = null;
      }
  }

  public function selectConnection()
  {
    // Do a round robin selection
    $this->index++;
    $this->index %= $this->size;
    if( $this->pool[$this->index] instanceof FCGI )
      if( $this->testConnection( $this->pool[$this->index] ) )
        return $this->pool[$this->index];

    $this->pool[$this->index] = $this->getConnection();
    return $this->pool[$this->index];
  }

  public function request( $uri, $params = Array(), $content = null )
  {
    $fcgi = $this->selectConnection();
    $request_id = $fcgi->request( $uri, $params, $content );

    $this->requests[ $request_id ] = $fcgi;
    return $request_id;
  }

  public function poll()
  {
    $count = 0;
    foreach( $this->pool as $index => $fcgi )
    {
      if( $fcgi instanceof FCGI )
      {
        try {
          $count+=$fcgi->poll();
        } catch( Exception $e )
        {
          echo __CLASS__ . "::poll unhandled error in poll on slot #$index\n";
          echo $e, "\n";
          // Destroy this connection
          $this->pool[$index] = null;
        }
      }
    }
    return $count;
  }

  public function expire($timeout)
  {
    $count = 0;
    foreach( $this->pool as $fcgi )
      if( $fcgi instanceof FCGI )
        if( $fcgi->requests() )
          $count+=$fcgi->expire($timeout);
    return $count;
  }

  public function purge($timeout)
  {
    $count = 0;
    foreach( $this->pool as $fcgi )
      if( $fcgi instanceof FCGI )
        if( $fcgi->requests() )
          $count+=$fcgi->purge($timeout);

    // Cleanup our request stack
    if( $count )
      foreach($this->requests as $request_id => $fcgi )
        if( $fcgi instanceof FCGI )
          if( ! $fcgi->completed($request_id) && ! $fcgi->active($request_id) )
            unset($this->requests[$request_id]);
    return $count;
  }

  public function response( $request_id )
  {
    if( array_key_exists($request_id, $this->requests) )
      return $this->requests[$request_id]->response($request_id);
    else
      throw new Exception( get_class($this) . "::response request_id $request_id does not exist");
  }

}
/*
// Example Usage:
include '../../batch/config.php';

$pool = new FCGIConnectionPool( 3 );
$pool->add( new URL( svConfig::get('app_url_dataserver') ) );
$requests = Array();
for( $i = 0; $i < 100; $i++ )
{
  $request_id = $pool->request(svConfig::get('app_path_bin_dataserver'), Array('PING' => Time::seconds()) );
  $requests[] = $request_id;
  print "Got request_id: $request_id\n";
}

$timer = new Timer();
$timer->start;
$results = 0;
$expired = 0;
$expire = new Timer();
$expire->start;
while(count($requests))
{
  if( $expired || $pool->poll() )
  {
    $expired = 0;
    foreach( $requests as $index => $request_id )
    {
      $response = $pool->response($request_id);
      if( $response )
      {
        $results++;
        if( $response->exception )
          print "$results. [$request_id] {$response->exception->getMessage()}\n";
        else
          print "$results. [$request_id] stdout: {$response->stdout}\n";
        unset($requests[$index]);
        //print_r($requests);
      } 
    }
  }

  if( $expire->elapsed > 10 && $pool->requests() )
  {
    $expired = $pool->expire(10);
    $expire->start;
    print "$expired requests expired\n";
  }
}
printf("elapsed: %.4f\n", $timer->elapsed);
*/
?>
