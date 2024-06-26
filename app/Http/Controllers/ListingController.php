<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;


class ListingController extends Controller
{
    public function index(Request $request)
    {
        $listings = Listing::where('is_active', true)
            ->with('tags')
            ->latest()
            ->get();

        $tags = Tag::orderBy('name')->get();

        if ($request->has('search-bar')) {
            $query = strtolower($request->get('search-bar'));
            $listings = $listings->filter(function ($listing) use ($query) {
                if (Str::contains(strtolower($listing->title), $query)) {
                    return true;
                }

                if (Str::contains(strtolower($listing->company), $query)) {
                    return true;
                }

                if (Str::contains(strtolower($listing->location), $query)) {
                    return true;
                }

                return false;
            });
        }
        if ($request->has('tag')) {
            $tag = $request->get('tag');
            $listings = $listings->filter(function ($listing) use ($tag) {
                return $listing->tags->contains('slug', $tag);
            });
        }

        return view('listings.index', compact('listings', 'tags'));
    }

    public function show(Listing $listing, Request $request)
    {
        return view('listings.show', compact('listing'));
    }

    public function apply(Listing $listing, Request $request)
    {
        $listing->clicks()
            ->create([
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip()
            ]);

        return redirect()->to($listing->apply_link);
    }

    public function create()
    {
        return view('listings.create');
    }

    public function store(Request $request)
    {
        // process the listing creation form
        $validationArray = [
            'title' => 'required',
            'company' => 'required',
            'logo' => 'file|max:2048',
            'location' => 'required',
            'apply_link' => 'required|url',
            'content' => 'required',
            'payment_method_id' => 'required'
        ];

        if (!Auth::check()) {
            $validationArray = array_merge($validationArray, [
                'email' => 'required|email|unique:users',
                'password' => 'required|confirmed|min:5',
                'name' => 'required'
            ]);
        }

        $request->validate($validationArray);

        // is a user signed in? if not, create one and authenticate
        $user = Auth::user();

        if (!$user) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password)
            ]);

            $user->createAsStripeCustomer();

            Auth::login($user);
        }

        // process the payment and create the listing
        try {
            $amount = 9900; // $99.00 USD in cents
            if ($request->filled('is_highlighted')) {
                $amount += 1900;
            }

            $user->charge($amount, $request->payment_method_id, [
                'return_url' => route('dashboard'),
            ]);


            $md = new \ParsedownExtra();

            $listing = $user->listings()
                ->create([
                    'title' => $request->title,
                    'slug' => Str::slug($request->title) . '-' . rand(1111, 9999),
                    'company' => $request->company,
                    'logo' => basename($request->file('logo')->store('public')),
                    'location' => $request->location,
                    'apply_link' => $request->apply_link,
                    'content' => $md->text($request->input('content')),
                    'is_highlighted' => $request->filled('is_highlighted'),
                    'is_active' => true
                ]);

            foreach (explode(',', $request->tags) as $requestTag) {
                $tag = Tag::firstOrCreate([
                    'slug' => Str::slug(trim($requestTag))
                ], [
                    'name' => ucwords(trim($requestTag))
                ]);

                $tag->listings()->attach($listing->id);
            }

            return redirect()->route('dashboard');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function edit($id)
    {
        $listing = Listing::findOrFail($id);
        $tags = Tag::orderBy('name')->get();
        return view('listings.edit', compact('listing', 'tags'));
    }

    public function update(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);

        $validationArray = [
            'title' => 'required',
            'company' => 'required',
            'logo' => 'file|max:2048',
            'location' => 'required',
            'apply_link' => 'required|url',
            'content' => 'required'
        ];

        $request->validate($validationArray);

        // Update logo if a new one is uploaded
        if ($request->hasFile('logo')) {
            $logo = $request->file('logo')->store('public');
            $listing->logo = basename($logo);
        }

        $listing->title = $request->title;
        $listing->company = $request->company;
        $listing->location = $request->location;
        $listing->apply_link = $request->apply_link;
        $listing->content = (new \ParsedownExtra())->text($request->input('content'));
        $listing->save();

        // Sync tags
        $tags = collect(explode(',', $request->tags))->map(function ($tag) {
            return Tag::firstOrCreate([
                'slug' => Str::slug(trim($tag))
            ], [
                'name' => ucwords(trim($tag))
            ])->id;
        });
        $listing->tags()->sync($tags);

        return redirect()->route('dashboard')->with('success', 'Listing updated successfully');
    }


    public function destroy($id)
    {
        try {
            $listing = Listing::findOrFail($id);
            $listing->delete();

            return redirect()->route('dashboard')->with('success', 'Listing deleted successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return redirect()->route('dashboard')->with('error', 'Listing not found');
        }
    }
}
