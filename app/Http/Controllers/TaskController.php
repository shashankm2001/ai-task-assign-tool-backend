<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Task;
use Illuminate\Support\Facades\Http;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        //
        $query = Task::where('user_id',$request->user()->id);

        if($request->filled('status'))
        {
            $query->where('status',$request->status);
        }

        if($request->filled('priority'))
        {
            $query->where('priority',$request->priority);
        }


        $tasks = $query->get();

        return response()->json([
            'tasks' =>$tasks
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    // public function create()
    // {
    //     //
        
        
    // }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $validated = $request->validate([

        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'due_date' => 'nullable|date',
        'priority' => 'required|in:LOW,MEDIUM,HIGH',
        'status' => 'required|in:TODO,IN_PROGRESS,DONE',
        ]);

        $validated['user_id'] = $request->user()->id;
        $task = Task::create($validated);

         return response()->json([
        'message' => 'Task created successfully',
        'task' => $task
    ], 201);

    }

    /**
     * Display the specified resource.
     */
    public function show( Request $request, string $id)
    {
        //
        $task =  Task::where('id',$id)
                ->where('user_id',$request->user()->id)
                ->first();
        
        if(!$task)
        {
            return response()->json([

                'message' => 'Task not found'
            ], 404);
        
        }
        return response()->json([
            'task' =>$task
        ],200);


    }

    /**
     * Show the form for editing the specified resource.
     */
    // public function edit(string $id)
    // {
    //     //
    // }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
         $validated = $request->validate([

        'title' => 'sometimes|string|max:255',
        'description' => 'nullable|string',
        'due_date' => 'nullable|date',
        'priority' => 'sometimes|in:LOW,MEDIUM,HIGH',
        'status' => 'sometimes|in:TODO,IN_PROGRESS,DONE',
        ]);

        $task = Task::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$task) {
            return response()->json([
                'message' => 'Task not found'
            ], 404);
        }
        $task->update($validated);

        return response()->json([
            'message' => 'Task updated successfully',
            'task' => $task
        ]);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request,  string $id)
    {
        //
        $task = Task::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$task) {
            return response()->json([
                'message' => 'Task not found'
            ], 404);
        }

        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully'
        ]);
    }
    public function aiSuggest(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255'
        ]);

        $prompt = "
A user entered the following task title:

{$request->title}

Generate:

1. A professional task description.
2. A suitable priority (LOW, MEDIUM or HIGH).

Return ONLY valid JSON like:

{
    \"description\":\"...\",
    \"priority\":\"HIGH\"
}
";

        try {
            $response = Http::timeout(30)
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . env('GEMINI_API_KEY'),
                    [
                        "contents" => [
                            [
                                "parts" => [
                                    [
                                        "text" => $prompt
                                    ]
                                ]
                            ]
                        ]
                    ]
                );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to reach the AI service. Please try again later.'
            ], 503);
        }

            if ($response->failed()) {
            return response()->json([
                'status' => $response->status(),
                'body' => $response->body(),
            ], 500);
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (!$text) {
            report(new \RuntimeException("Unexpected Gemini response shape: {$response->body()}"));

            return response()->json([
                'message' => 'The AI service returned an unexpected response.'
            ], 502);
        }

        $cleaned = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $suggestion = json_decode($cleaned, true);

        if (
            !is_array($suggestion)
            || !isset($suggestion['description'], $suggestion['priority'])
            || !in_array($suggestion['priority'], ['LOW', 'MEDIUM', 'HIGH'], true)
        ) {
            report(new \RuntimeException("Could not parse Gemini suggestion: {$text}"));

            return response()->json([
                'message' => 'The AI service returned an unexpected response.'
            ], 502);
        }

        return response()->json([
            'description' => $suggestion['description'],
            'priority' => $suggestion['priority'],
        ]);
    }

}
