<?php
/**
 * 이 파일은 미니톡 클라이언트의 일부입니다. (https://www.minitalk.io)
 *
 * 미니톡 서버의 핑퐁메시지를 처리한다.
 * 
 * @file /api/ping.post.php
 * @author Arzz (arzz@arzz.com)
 * @license MIT License
 * @version 7.0.0
 * @modified 2020. 9. 16.
 */
if (defined('__MINITALK__') == false) exit;

$key = Request('key');
$domain = Request('domain');
$time = Request('time');

if (strlen($key) == 0 || $key != $_CONFIGS->key || strlen($domain) == 0) {
	$data->success = false;
	$data->message = 'MISSING PARAMTERS : SECRET_KEY OR DOMAIN';
	return;
}

$this->db()->update($this->table->server,array('status'=>'ONLINE','latest_update'=>time()))->where('domain',$domain)->getOne();

$data->success = true;
$data->client = round(array_sum(explode(' ',microtime())) * 1000);
$data->server = $time;
$data->timegap = sprintf('%0.3f',abs($data->client - $data->server) / 1000);
?>