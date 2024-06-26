<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\UserReport;
use App\Models\UserReportFile;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary as CloudinaryLabs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserReportController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $report = UserReport::with([
            'reportFiles',
            'reporter:id,name,username,email,profile_picture',
            'reported:id,name,username,email,profile_picture'
        ])
            ->when($status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($startDate, function ($query, $startDate) {
                return $query->whereDate('created_at', '>=', $startDate);
            })
            ->when($endDate, function ($query, $endDate) {
                return $query->whereDate('created_at', '<=', $endDate);
            })
            ->get();

        return response()->json($report);
    }


    public function store(Request $request)
    {
        // Validate the incoming request
        $request->validate([
            'reported_user_id' => 'exists:users,id',
            'files.*' => 'file|mimes:jpg,jpeg,png,pdf,doc,docx,txt|max:2048' // Adjust mime types and size limit as needed
        ]);

        try {
            DB::beginTransaction();
            $reporter = auth()->user()->id;
            // Create the user report
            $userReport = UserReport::create([
                'reporter_id' => $reporter,
                'reported_user_id' => $request->reported_user_id,
                'report_description' => $request->report_description
            ]);

            // Handle file uploads
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    // Store the file
                    $filePath = $file->getRealPath();
                    $uploadResult = CloudinaryLabs::upload($filePath)->getSecurePath();
                    $url = $uploadResult;
                    // Save the file details in UserReportFile model
                    UserReportFile::create([
                        'user_report_id' => $userReport->id,
                        'original_file_name' => $file->getClientOriginalName(),
                        'files' => $url,
                        'mime_type' => $file->getClientMimeType()
                    ]);
                }
            }
            DB::commit();
            // Return a response
            return response()->json(['message' => 'Report created successfully'], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            report($exception);
            return response()->json([
                'message' => 'Unable to create your report!',
                'error' => $exception->getMessage()
            ], 422);
        }
    }

    public function updateReportStatus(UserReport $userReport, Request $request)
    {
        // Validate the status input
        $validated = $request->validate([
            'status' => 'required|in:completed,pending'
        ]);

        // Update the report status
        $userReport->update([
            'status' => $validated['status']
        ]);

        return response()->json([
            'message' => 'Report status successfully updated'
        ]);
    }
}
