<?php

use App\Http\Controllers\Menu\MenuController;
use Illuminate\Support\Facades\Route;

// egmenu.app/{tenant}/... (tenancy initialised by IdentifyTenantByPath)
Route::get('/', [MenuController::class, 'show'])->name('show');
Route::get('/t/{token}', [MenuController::class, 'table'])->where('token', '[a-f0-9]{20}')->name('table');
