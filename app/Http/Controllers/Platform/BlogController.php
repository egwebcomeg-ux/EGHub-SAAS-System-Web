<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** egmenu.app/blog — articles for cafe and restaurant owners, and the RSS feed of the latest twenty. */
class BlogController extends Controller
{
    public function index(): View
    {
        return view('landing.blog.index', [
            'posts' => Post::query()->published()->paginate(9),
        ]);
    }

    public function show(string $slug): View
    {
        $post = Post::query()->published()->where('slug', $slug)->firstOrFail();
        $others = Post::query()->published()->where('id', '!=', $post->id)->limit(3)->get();

        return view('landing.blog.show', ['post' => $post, 'others' => $others]);
    }

    public function rss(): Response
    {
        $posts = Post::query()->published()->limit(20)->get();
        $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'."\n<channel>\n"
            .'<title>'.$esc(__('EgMenu blog')).'</title>'."\n"
            .'<link>'.$esc(route('blog')).'</link>'."\n"
            .'<description>'.$esc(__('Practical articles for cafe and restaurant owners in Egypt: menus, ordering, loyalty and running the place.')).'</description>'."\n"
            .'<language>'.(app()->getLocale() === 'en' ? 'en' : 'ar').'</language>'."\n"
            .'<atom:link href="'.$esc(route('blog.rss')).'" rel="self" type="application/rss+xml" />'."\n";
        foreach ($posts as $post) {
            $out .= "<item>\n"
                .'<title>'.$esc($post->displayTitle()).'</title>'."\n"
                .'<link>'.$esc(route('blog.show', $post->slug)).'</link>'."\n"
                .'<guid isPermaLink="true">'.$esc(route('blog.show', $post->slug)).'</guid>'."\n"
                .'<pubDate>'.$post->published_at->toRssString().'</pubDate>'."\n"
                .'<description>'.$esc($post->displayExcerpt() ?: $post->displayDescription()).'</description>'."\n"
                ."</item>\n";
        }
        $out .= "</channel>\n</rss>\n";

        return response($out, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
