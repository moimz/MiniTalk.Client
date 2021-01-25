<?php
/**
 * 이 파일은 미니톡 클라이언트의 일부입니다. (https://www.minitalk.io)
 *
 * 미니톡 클라이언트의 파일 업로드를 처리한다.
 * 
 * @file /process/uploadFiles.php
 * @author Arzz (arzz@arzz.com)
 * @license MIT License
 * @version 6.4.0
 * @modified 2021. 1. 25.
 */
if (defined('__MINITALK__') == false) exit;

$code = Request('code');
$drafts = Request('drafts') ? json_decode(Request('drafts')) : null;

if ($code != null) {
	$hash = Decoder($code);
	if ($hash === false) {
		$results->success = false;
		$results->message = $this->getErrorText('NOT_FOUND');
		return;
	}
	
	$file = $this->db()->select($this->table->attachment)->where('hash',$hash)->getOne();
	if ($file === null) {
		$results->success = false;
		$results->message = $this->getErrorText('NOT_FOUND');
		return;
	}
	
	if (isset($_SERVER['HTTP_CONTENT_RANGE']) == true && preg_match('/bytes ([0-9]+)\-([0-9]+)\/([0-9]+)/',$_SERVER['HTTP_CONTENT_RANGE'],$fileRange) == true) {
		$chunkBytes = file_get_contents("php://input");;
		$chunkStart = intval($fileRange[1]);
		$chunkEnd = intval($fileRange[2]);
		$fileSize = intval($fileRange[3]);
		
		if ($fileSize != $file->size) {
			$results->success = false;
			$results->message = $this->getErrorText('INVALID_FILE_SIZE');
			return;
		}
		
		if ($chunkEnd - $chunkStart + 1 != strlen($chunkBytes)) {
			$results->success = false;
			$results->message = $this->getErrorText('INVALID_CHUNK_SIZE');
			return;
		}
		
		if ($chunkStart == 0) $fp = fopen($this->getAttachmentPath().'/'.$file->path,'w');
		else $fp = fopen($this->getAttachmentPath().'/'.$file->path,'a');
		
		fseek($fp,$chunkStart);
		fwrite($fp,$chunkBytes);
		fclose($fp);
		
		/**
		 * 최종적으로 파일업로드가 완료되었을때, 파일의 원본크기와 업로드된 파일의 크기를 비교하여, 일치할 경우 파일업로드를 완료처리하고,
		 * 서로 일치하지 않을경우 업로드 실패 메시지를 전송한다.
		 */
		if ($chunkEnd + 1 === $fileSize) {
			if (intval($file->size) != filesize($this->getAttachmentPath().'/'.$file->path)) {
				unlink($this->getAttachmentPath().'/'.$file->path);
				$this->db()->delete($this->table->attachment)->where('hash',$file->hash)->execute();
				$results->success = false;
				$results->message = $this->getErrorText('INVALID_UPLOADED_SIZE');
			} else {
				$fileHash = md5_file($this->getAttachmentPath().'/'.$file->path);
				if (is_dir($this->getAttachmentPath().'/'.date('Ym',$file->reg_date)) === false) {
					mkdir($this->getAttachmentPath().'/'.date('Ym',$file->reg_date));
					chmod($this->getAttachmentPath().'/'.date('Ym',$file->reg_date),0707);
				}
				
				$filePath = date('Ym',$file->reg_date).'/'.$fileHash.'.'.base_convert(microtime(true)*10000,10,32).'.'.$this->getFileExtension($file->name,$this->getAttachmentPath().'/'.$file->path);
				rename($this->getAttachmentPath().'/'.$file->path,$this->getAttachmentPath().'/'.$filePath);
				chmod($this->getAttachmentPath().'/'.$filePath,0707);
				
				$insert = array();
				$insert['mime'] = $this->getFileMime($this->getAttachmentPath().'/'.$filePath);
				$insert['type'] = $this->getFileType($insert['mime']);
				$insert['path'] = $filePath;
				$insert['status'] = 'PUBLISHED';
				
				if ($insert['type'] == 'image') {
					$check = getimagesize($this->getAttachmentPath().'/'.$filePath);
					$insert['width'] = $check[0];
					$insert['height'] = $check[1];
				}
				
				$this->db()->update($this->table->attachment,$insert)->where('hash',$hash)->execute();
				
				$file = $this->db()->select($this->table->attachment,'name,size,type,width,height,exp_date')->where('hash',$hash)->getOne();
				
				$results->success = true;
				$file->extension = $this->getFileExtension($file->name);
				
				if ($file->type == 'image') {
					$file->view = $this->getClientProcessUrl('attachment',true).'/view/'.$hash.'/'.$file->name;
				} else {
					unset($file->width);
					unset($file->height);
				}
				
				$file->download = $this->getClientProcessUrl('attachment',true).'/download/'.$hash.'/'.$file->name;
				$results->file = $file;
			}
		} else {
			$results->success = true;
			$results->uploaded = filesize($this->getAttachmentPath().'/'.$file->path);
		}
	} else {
		$results->success = false;
		$results->message = $this->getErrorText('INVALID_HTTP_CONTENT_RANGE');
	}
	
//	$results->success = true;
} elseif ($drafts != null) {
	$channel = Request('channel');
	$user = json_decode(Request('user'));
	for ($i=0, $loop=count($drafts);$i<$loop;$i++) {
		$this->db()->setLockMethod('WRITE')->lock($this->table->attachment);
		while (true) {
			$hash = md5(json_encode($drafts[$i]).time().rand(10000,99999));
			if ($this->db()->select($this->table->attachment)->where('hash',$hash)->has() === false) {
				$insert = array('hash'=>$hash,'channel'=>$channel,'name'=>$drafts[$i]->name,'path'=>'temp/'.$hash,'size'=>$drafts[$i]->size,'type'=>'','mime'=>'','nickname'=>$user->nickname,'ip'=>GetClientIp(),'reg_date'=>time(),'exp_date'=>0);
				$this->db()->insert($this->table->attachment,$insert)->execute();
				break;
			}
		}
		$this->db()->unlock();
		
		$drafts[$i]->code = Encoder($hash);
	}
	
	$results->success = true;
	$results->drafts = $drafts;
}
?>