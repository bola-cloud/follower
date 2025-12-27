<?php

namespace App\Http\Controllers\Admin\front;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Slider;
use Illuminate\Support\Facades\Storage;

class SliderController extends Controller
{
    public function index()
    {
        $sliders = Slider::orderBy('order')->paginate(20);
        return view('admin.sliders.index', compact('sliders'));
    }

    public function create()
    {
        return view('admin.sliders.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('sliders', 'public');
            $data['image'] = $path;
        }
        $data['is_active'] = isset($data['is_active']) ? (bool)$data['is_active'] : true;
        Slider::create($data);
        return redirect()->route('admin.sliders.index')->with('success','Slider created');
    }

    public function edit(Slider $slider)
    {
        return view('admin.sliders.edit', compact('slider'));
    }

    public function update(Request $request, Slider $slider)
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        if ($request->hasFile('image')) {
            if ($slider->image) {
                Storage::disk('public')->delete($slider->image);
            }
            $path = $request->file('image')->store('sliders', 'public');
            $data['image'] = $path;
        }
        $data['is_active'] = isset($data['is_active']) ? (bool)$data['is_active'] : false;
        $slider->update($data);
        return redirect()->route('admin.sliders.index')->with('success','Slider updated');
    }

    public function destroy(Slider $slider)
    {
        if ($slider->image) {
            Storage::disk('public')->delete($slider->image);
        }
        $slider->delete();
        return back()->with('success','Slider deleted');
    }

    // Toggle active state (quick action)
    public function toggle(Slider $slider)
    {
        $slider->is_active = !$slider->is_active;
        $slider->save();
        if (request()->wantsJson()) {
            return response()->json(['status' => 'ok', 'is_active' => $slider->is_active]);
        }
        return back()->with('success', 'Slider updated');
    }

    // Quick update of order from index
    public function updateOrder(Request $request, Slider $slider)
    {
        $data = $request->validate([
            'order' => 'required|integer',
        ]);
        $slider->order = $data['order'];
        $slider->save();
        return back()->with('success', 'Order updated');
    }
}
