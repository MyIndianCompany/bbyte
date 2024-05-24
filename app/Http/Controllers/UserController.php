<?php
namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Post;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserWebsiteUrl;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function updateProfile(UserRequest $request)
    {
        $user = auth()->user();
        try {
            DB::beginTransaction();
            $user->update([
                'name' => $request->has('name') ? $request->input('name') : $user->name,
                'username' => $request->has('username') ? $request->input('username') : $user->username,
                'email' => $request->has('email') ? $request->input('email') : $user->email
            ]);
            $userProfile = $user->profile;
            if (!$userProfile) {
                $userProfile = new UserProfile();
            }
            $userProfile->fill([
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'bio' => request()->has('bio') ? request()->input('bio') : $userProfile->bio,
                'gender' => request()->has('gender') ? request()->input('gender') : $userProfile->gender,
                'custom_gender' => request()->has('custom_gender') ? request()->input('custom_gender') : $userProfile->custom_gender,
                'profile_picture' => request()->has('profile_picture') ? request()->input('profile_picture') : NULL,
            ]);
            $user->profile()->save($userProfile);

            $urls = $request->input('urls', []);
            $existingUrls = $user->website->pluck('url')->toArray();

            foreach ($urls as $url) {
                if (!in_array($url, $existingUrls) && count($existingUrls) < 5) {
                    $userWebsite = new UserWebsiteUrl(['url' => $url]);
                    $user->website()->save($userWebsite);
                    $existingUrls[] = $url;
                }
            }
            DB::commit();
            return response()->json([
                'message' => 'Done'
            ]);
        } catch (\Exception $exception) {
            DB::rollBack();
            report($exception);
            return response()->json([
                'message' => 'An error occurred',
                'error' => $exception->getMessage()
            ], 500);
        }
    }

    public function userProfile($username)
    {
        $user = DB::table('users')
            ->where('users.username', $username)
            ->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
            ->leftJoin('user_website_urls', 'users.id', '=', 'user_website_urls.user_id')
            ->select(
                'users.id',
                'users.name',
                'users.username',
                'users.email',
                'user_profiles.bio',
                'user_profiles.gender',
                'user_profiles.custom_gender',
                'user_profiles.profile_picture',
                'user_profiles.post_count',
                'user_profiles.follower_count',
                'user_profiles.following_count',
                'user_profiles.created_at',
                'user_website_urls.url'
            )
            ->get()
            ->groupBy('id');

        if ($user->isEmpty()) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user = $user->map(function ($user) {
            $urls = $user->pluck('url')->toArray();
            unset($user[0]->url); // Remove the first URL from the main object
            $user[0]->urls = $urls; // Add URLs as an array to the main object
            return $user[0];
        });

        return response()->json($user->first());
    }

    public function getAllUserProfile()
    {
        // Retrieve only specified fields from the user_profiles table without related data
        $userProfiles = UserProfile::select(
            'user_id',
            'name',
            'username',
            'email',
            'bio',
            'gender',
            'custom_gender',
            'profile_picture',
            'post_count',
            'follower_count',
            'following_count'
        )->get();

        // Transform the result set to exclude any unexpected data
        $userProfiles = $userProfiles->map(function ($profile) {
            return [
                'user_id' => $profile->user_id,
                'name' => $profile->name,
                'username' => $profile->username,
                'email' => $profile->email,
                'bio' => $profile->bio,
                'gender' => $profile->gender,
                'custom_gender' => $profile->custom_gender,
                'profile_picture' => $profile->profile_picture,
                'post_count' => $profile->post_count,
                'follower_count' => $profile->follower_count,
                'following_count' => $profile->following_count,
            ];
        });

        // Return the transformed profiles
        return $userProfiles;
    }

    public function getFollowersByUsername($username)
    {
        $user = User::where('username', $username)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $followers = $user->followers;
        return response()->json($followers);
    }

    public function getFollowingsByUsername($username)
    {
        $user = User::where('username', $username)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $following = $user->following;
        return response()->json($following);
    }

    public function getUserCounts()
    {
        $userCount = User::has('profile')->count();
        $postCount = Post::has('user')->count();
        return response()->json([
            'total_user_count'  => $userCount,
            'total_post_count'  => $postCount
        ]);
    }
}

