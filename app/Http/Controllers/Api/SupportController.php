<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendFeatureRequestEmail;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SupportController extends Controller
{
    public function feedback(Request $request)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'message' => 'nullable|string|max:2000',
        ]);

        // Log the feedback
        ActivityLog::log(
            'support.feedback',
            "User submitted feedback: {$request->rating}/5 stars" . ($request->message ? " - {$request->message}" : ''),
            $request->user(),
            [
                'type' => 'feedback',
                'rating' => $request->rating,
                'message' => $request->message,
            ]
        );

        // Send notification email to admin
        try {
            Mail::raw(
                "New Feedback from {$request->user()->name} ({$request->user()->email})\n\n" .
                "Rating: {$request->rating}/5\n" .
                "Message: " . ($request->message ?: 'No message') . "\n",
                function ($mail) {
                    $mail->to(Setting::get('support_email', config('mail.from.address', 'support@esperworks.com')))
                         ->subject('EsperWorks Feedback Received');
                }
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send feedback email', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id
            ]);
        }

        return response()->json(['message' => 'Thank you for your feedback!']);
    }

    public function featureRequest(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'priority' => 'nullable|string|in:low,medium,high',
        ]);

        ActivityLog::log(
            'support.feature_request',
            "Feature request: {$request->title} (priority: {$request->priority}) - {$request->description}",
            $request->user(),
            [
                'type' => 'features',
                'title' => $request->title,
                'description' => $request->description,
                'priority' => $request->priority,
            ]
        );

        // Send admin email after response so the API returns immediately (avoids long wait from Mail::raw)
        SendFeatureRequestEmail::dispatch(
            $request->user()->name,
            $request->user()->email,
            $request->title,
            $request->priority,
            $request->description
        )->afterResponse();

        return response()->json(['message' => 'Thank you for supporting us with your feature idea!']);
    }

    public function contact(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        ActivityLog::log(
            'support.contact',
            "Support contact: {$request->subject} - {$request->message}",
            $request->user(),
            [
                'type' => 'contact',
                'subject' => $request->subject,
                'message' => $request->message,
            ]
        );

        try {
            Mail::raw(
                "Support Message from {$request->user()->name} ({$request->user()->email})\n\n" .
                "Subject: {$request->subject}\n" .
                "Message: {$request->message}\n",
                function ($mail) use ($request) {
                    $mail->to(Setting::get('support_email', config('mail.from.address', 'support@esperworks.com')))
                         ->replyTo($request->user()->email, $request->user()->name)
                         ->subject("EsperWorks Support: {$request->subject}");
                }
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send support contact email', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id
            ]);
        }

        return response()->json(['message' => 'Message sent! We\'ll get back to you within 24 hours.']);
    }
}
