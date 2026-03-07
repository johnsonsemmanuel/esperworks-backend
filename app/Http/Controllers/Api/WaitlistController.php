<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Waitlist;
use App\Models\User;
use App\Mail\WaitlistConfirmationMail;
use App\Mail\WaitlistAdminNotifyMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class WaitlistController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
        ]);

        $existing = Waitlist::where('email', $request->email)->first();
        if ($existing) {
            return response()->json(['message' => "You're already on the waitlist! We'll notify you when we launch."], 200);
        }

        $entry = Waitlist::create([
            'email' => $request->email,
            'phone' => $request->phone ? strip_tags($request->phone) : null,
            'country' => $request->country ? strip_tags($request->country) : null,
        ]);
        $totalCount = Waitlist::count();

        // Send confirmation email to the user
        try {
            Mail::to($request->email)->send(new WaitlistConfirmationMail($request->email));
        } catch (\Exception $e) {
            // Don't fail the request if email fails
        }

        // Notify admin(s)
        try {
            $admins = User::where('role', 'admin')->pluck('email');
            foreach ($admins as $adminEmail) {
                Mail::to($adminEmail)->send(new WaitlistAdminNotifyMail($request->email, $request->phone, $totalCount));
            }
        } catch (\Exception $e) {
            // Don't fail the request if admin email fails
        }

        return response()->json([
            'message' => "You're on the list! Check your email for confirmation.",
            'position' => $totalCount,
        ], 201);
    }

    public function index()
    {
        $entries = Waitlist::latest()->paginate(50);
        return response()->json($entries);
    }

    public function count()
    {
        return response()->json(['count' => Waitlist::count()]);
    }
}
