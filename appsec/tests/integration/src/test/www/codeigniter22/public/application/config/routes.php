<?php if (! defined('BASEPATH')) exit('No direct script access allowed');

$route['default_controller'] = 'welcome';
$route['404_override'] = '';

$route['archive/([0-9]{4})-([0-9]{2})'] = 'normalized/archive/$1/$2';
$route['releases/v1.0'] = 'normalized/literal';
$route['articles(?:/([0-9]+))?'] = 'normalized/optional';

/* End of file routes.php */
/* Location: ./application/config/routes.php */
