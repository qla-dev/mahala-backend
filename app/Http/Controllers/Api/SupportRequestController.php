<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportRequest;
use Illuminate\Http\Request;

class SupportRequestController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        $ticket = SupportRequest::query()->create($data);

        return response()->json(['message' => 'Poruka je zaprimljena.', 'data' => ['id' => $ticket->id]], 201);
    }
}
