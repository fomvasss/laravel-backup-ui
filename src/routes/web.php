<?php

use Illuminate\Support\Facades\Route;

Route::get('/', 'BackupController@index')->name('backup-ui.index');
Route::post('/create', 'BackupController@create')->name('backup-ui.create');
Route::get('/status', 'BackupController@status')->name('backup-ui.status');
Route::get('/download/{disk}/{path}', 'BackupController@download')
    ->where('path', '.*')
    ->name('backup-ui.download');
Route::delete('/delete/{disk}/{path}', 'BackupController@delete')
    ->where('path', '.*')
    ->name('backup-ui.delete');
Route::post('/clean', 'BackupController@clean')->name('backup-ui.clean');
Route::get('/diagnostics', 'DiagnosticsController@index')->name('backup-ui.diagnostics');
