<?php namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use CustomFacades\ModalHelpers\HistoryExportModalHelper;

class HistoryExportController extends Controller
{
	public function generate()
	{

		$data = HistoryExportModalHelper::get();
		
		return response()->json($data);
	}
	
	/**
	 * Descarga desde la app: archivo y nombre van en la query, junto al user_api_hash.
	 */
	public function apiDownload()
	{
		return $this->download((string)request()->get('file', ''), (string)request()->get('name', ''));
	}

	public function download( $file, $filename )
	{
		$path = HistoryExportModalHelper::getFile($file);
		
		if ( ! file_exists($path) )
		{
			return;
		}

        //return response()->download($path, $filename)->deleteFileAfterSend(true)->send();
		return response()->download($path, $filename)->deleteFileAfterSend(true);
	}
}