<?php

use Fomvasss\LaravelBackupUi\Http\Controllers\BackupController;
use Fomvasss\LaravelBackupUi\Http\Controllers\DiagnosticsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BackupController::class, 'index'])->name('backup-ui.index');
Route::post('/create', [BackupController::class, 'create'])->name('backup-ui.create');
Route::get('/status', [BackupController::class, 'status'])->name('backup-ui.status');
Route::get('/download/{disk}/{path}', [BackupController::class, 'download'])
    ->where('path', '.*')
    ->name('backup-ui.download');
Route::delete('/delete/{disk}/{path}', [BackupController::class, 'delete'])
    ->where('path', '.*')
    ->name('backup-ui.delete');
Route::post('/clean', [BackupController::class, 'clean'])->name('backup-ui.clean');
Route::get('/diagnostics', [DiagnosticsController::class, 'index'])->name('backup-ui.diagnostics');
