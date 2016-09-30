<?php
/** Lettuce Boostrap File
 *  Written by Ben Blazely
 *  Named for my wife Loretta :-)
 */

define('LETTUCE_SERVER_PATH', __DIR__);
include_once(LETTUCE_SERVER_PATH . '/root/Root.php');
new LettuceRoot();  // Bootstrap class instantiation, no need to store it to a variable as we don't address it directly
