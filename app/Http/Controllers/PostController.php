<?php

namespace App\Http\Controllers;

use App\Common\Constant\Constants;
use App\Exceptions\CustomException\BbyteException;
use App\Models\Follower;
use App\Models\Post;
use App\Models\PostComment;
use App\Services\Posts\PostServices;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class PostController extends Controller
{
    protected $postService;

    public function __construct(PostServices $postService)
    {
        $this->postService = $postService;
    }

    public function index()
    {
        $posts = $this->postService->getPostsQuery()->get();
        return response()->json($posts, 201);
    }

    public function getPostsByUserId(Request $request, $userId)
    {
        $posts = $this->postService->getPostsQuery()
            ->where('user_id', $userId)
            ->get();
        return response()->json($posts, 201);
    }

    public function getPostsByAuthUsers(Request $request)
    {
        $authUserId = Auth::id();
        $followedUserIds = Follower::where('follower_user_id', $authUserId)
            ->pluck('following_user_id')
            ->toArray();

        $posts = $this->postService->getPostsQuery()->get();
        $posts->each(function ($post) use ($authUserId, $followedUserIds) {
            $post->comment_count = $post->comments->count();
            $post->reply_count = $post->comments->flatMap->replies->count();
            $post->nested_reply_count = $post->comments->flatMap->replies->flatMap->replies->count();
            $post->liked = $post->likes->contains('user_id', $authUserId);
            $post->followed = in_array($post->user->id, $followedUserIds);
            $post->is_owner = $post->user->id === $authUserId;
            unset($post->likes);
        });

        return response()->json($posts, 201);
    }

    public function getPosts(Request $request)
    {
        $user = Auth::user();
        $userId = $request->input('user_id', $user->id);
        $posts = $this->postService->getPostsQuery()
            ->where('user_id', $userId)
            ->get();
        return response()->json($posts, 201);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:mp4,mov,avi|max:102400',
        ]);
        $uploadedFile = $request->file('file');
        try {
            DB::beginTransaction();
            $originalFileName = $uploadedFile->getClientOriginalName();
            $uploadedVideo    = Cloudinary::uploadVideo($uploadedFile->getRealPath());
            $videoUrl         = $uploadedVideo->getSecurePath();
            $publicId         = $uploadedVideo->getPublicId();
            $fileSize         = $uploadedVideo->getSize();
            $fileType         = $uploadedVideo->getFileType();
            $width            = $uploadedVideo->getWidth();
            $height           = $uploadedVideo->getHeight();
            if (!$uploadedFile) {
                throw new BbyteException('File not found!');
            }
            $user = auth()->user()->id;
            Post::create([
                'user_id'            => $user,
                'caption'            => $request->input('caption'),
                'original_file_name' => $originalFileName,
                'file_url'           => $videoUrl,
                'public_id'          => $publicId,
                'file_size'          => $fileSize,
                'file_type'          => $fileType,
                'mime_type'          => $uploadedFile->getMimeType(),
                'width'              => $width,
                'height'             => $height,
            ]);
            DB::commit();
            return response()->json(['success' => 'Your post has been successfully uploaded!'], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            report($exception);
            return response()->json([
                'message' => 'Failed to process the transaction. Please try again later.',
                'error' => $exception->getMessage()
            ], 422);
        }
    }

    public function destroy(Post $post)
    {
        try {
            if ($post->user_id !== auth()->user()->id) {
                return response()->json(['message' => 'You are not authorized to delete this post.'], 403);
            }
            Cloudinary::destroy($post->public_id);
            $post->delete();
            return response()->json(['success' => 'Post deleted successfully.'], 201);
        } catch (\Exception $exception) {
            report($exception);
            return response()->json([
                'message' => 'Failed to delete the post. Please try again later.',
                'error' => $exception->getMessage()
            ], 422);
        }
    }
    public function like(Post $post)
    {
        try {
            DB::beginTransaction();
            $user = auth()->user();
            $like = $user->likes()->where('post_id', $post->id)->first();
            $like ? $like->delete() : $user->likes()->create(['post_id' => $post->id]);
            $post->update(['like_count' => $post->likes()->count()]);
            DB::commit();
            return response()->json(['message' => 'Post liked successfully.'], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            report($exception);
            return response()->json([
                'message' => 'Failed to like the post. Please try again later.',
                'error' => $exception->getMessage()
            ], 401);
        }
    }

    public function comment(Request $request, Post $post)
    {
        $request->validate([
            'comment' => 'required|string|max:255',
        ]);

        try {
            DB::beginTransaction();
            $user = auth()->user();
            PostComment::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'comment' => $request->input('comment')
            ]);
            $post->update(['comment_count' => $post->comments()->count()]);
            DB::commit();
            return response()->json(['message' => 'Comment added successfully.'], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            report($exception);
            return response()->json([
                'message' => 'Failed to add comment. Please try again later.',
                'error' => $exception->getMessage()
            ], 401);
        }
    }

    public function commentLike(PostComment $postComment)
    {
        try {
            DB::beginTransaction();
            $user = auth()->user();
            $commentLikes = $user->commentLikes()->where('comment_id', $postComment->id)->first();
            $commentLikes ? $commentLikes->delete() : $user->commentLikes()->create(['comment_id' => $postComment->id]);
            $postComment->update(['comment_like_count' => $postComment->likes()->count()]);
            DB::commit();
            return response()->json(['message' => 'Post Comment liked successfully.'], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            report($exception);
            return response()->json([
                'message' => 'Failed to like the post comment. Please try again later.',
                'error' => $exception->getMessage()
            ], 401);
        }
    }

    //Comment Reply
    public function reply(Request $request, Post $post, PostComment $comment)
    {
        $request->validate([
            'comment' => 'required|string|max:255',
        ]);

        try {
            DB::beginTransaction();
            $user = auth()->user();
            $reply = PostComment::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'super_comment_id' => $comment->id,
                'comment' => $request->input('comment')
            ]);
            $comment->update(['comment_reply_count' => $comment->replies()->count()]);
            DB::commit();
            return response()->json(['message' => 'Comment reply added successfully.', 'reply' => $reply], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            report($exception);
            return response()->json([
                'message' => 'Failed to add comment reply. Please try again later.',
                'error' => $exception->getMessage()
            ], 401);
        }
    }
}
