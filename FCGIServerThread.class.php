<?php
/* FCGIServerThread.class.php - Implementation of the FastCGI Protocol in PHP
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

class FCGIServerThread extends FCGIServer
{
	public function __construct( IOMessageQueue $socket ) 
  {
		$this->socket = $socket;
    $this->active = Array();
    $this->completed = Array();
    $this->readBuffer = '';
    $this->writeBuffer = '';
  }

  public function __key()
  {
    return $this->socket->__key();
  }

  public function canRead()
  {
    try {
      return parent::canRead();
    } catch( Exception $e )
    {
      switch($e->getCode())
      {
        case 42:
          // no data;
          return null;
          break;
        default:
          throw $e;
      }
    }
  }

  public function canWrite()
  {
    try {
      return parent::canWrite();
    } catch( Exception $e )
    {
      switch($e->getCode())
      {
        case 42:
          // no data;
          return null;
          break;
        default:
          throw $e;
      }
    }
  }

  public function poll()
  {
    $this->canRead();
    $this->canWrite();
    $this->decodePackets();
  }

  public function isConnected()
  {
    return true;
  }

  public function connect( URL $url )
  {
    throw new Exception( get_class($this) . "::connect not implemented");
  }

  public function close()
  {
    $this->socket->detach();
  }
}


?>
