<?php

namespace App\Http\Controllers;

use App\Models\Follower;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowerController extends Controller
{
    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query');

        $users = User::where('name', 'like', "%$query%")
            ->orWhere('username', 'like', "%$query%")
            ->get(['id', 'name', 'username', 'profile_picture']);

        return response()->json($users);
    }

    public function follow(User $user): \Illuminate\Http\JsonResponse
    {
        try {
            $currentUser = auth()->user();

            if ($currentUser->id === $user->id) {
                return response()->json(['message' => 'You cannot follow yourself.'], 400);
            }

            if ($currentUser->following()->where('following_user_id', $user->id)->exists()) {
                return response()->json(['message' => 'You are already following this user.'], 400);
            }

            // Eager load the 'profile' relationship for both users
            $currentUser->load('profile');
            $user->load('profile');

            DB::beginTransaction();

            $currentUser->following()->attach($user->id);

            // Check if 'profile' relationship is loaded for $currentUser
            if ($currentUser->profile) {
                $currentUser->profile->increment('following_count');
            } else {
                throw new \Exception('Profile not loaded for current user.');
            }

            // Check if 'profile' relationship is loaded for $user
            if ($user->profile) {
                $user->profile->increment('follower_count');
            } else {
                throw new \Exception('Profile not loaded for the user being followed.');
            }

            DB::commit();

            return response()->json([
                'message' => 'User followed successfully'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            Log::error('Follow error: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'An error occurred while trying to follow the user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function following(): \Illuminate\Http\JsonResponse
    {
        $following = auth()->user()->following()->select(['users.id', 'users.name', 'users.username', 'users.profile_picture'])->get();
        return response()->json($following);
    }

    public function followers(): \Illuminate\Http\JsonResponse
    {
        $followers = auth()->user()->followers()->select(['users.id', 'users.name', 'users.username', 'users.profile_picture'])->get();
        return response()->json($followers);
    }
}
