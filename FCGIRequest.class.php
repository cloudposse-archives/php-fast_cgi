<?php
/* FCGIRequest.class.php - Implementation of the FastCGI Protocol in PHP
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

class FCGIRequest
{
  public $request_id;
  public $params;
  public $stdin;
  public $exception;
  public $complete;
  public $timer;

  public function __construct($request_id)
  {
    $this->request_id = $request_id;
    $this->params     = Array();
    $this->stdin      = '';
    $this->exception  = null;
    $this->complete   = false;
    $this->timer      = new Timer();
    $this->timer->start;
  }

  public function __destruct()
  {
    unset($this->request_id);
    unset($this->params);
    unset($this->stdin);
    unset($this->exception);
    unset($this->complete);
    unset($this->timer);
  }
}

?>
