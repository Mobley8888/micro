<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;

class LeadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('leads.index', ['leads' => Lead::query()->where('company_id', $this->currentCompanyId())->with('source')->latest()->paginate(15)]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('leads.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLeadRequest $request)
    {
        Lead::create([...$request->validated(), 'company_id' => $this->currentCompanyId(), 'owner_id' => auth()->id()]);

        return redirect()->route('leads.index')->with('success', 'Prospect créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Lead $lead)
    {
        $this->ensureCompanyOwnsLead($lead);

        return view('leads.show', compact('lead'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Lead $lead)
    {
        $this->ensureCompanyOwnsLead($lead);

        return view('leads.edit', compact('lead'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Lead $lead)
    {
        abort(501, 'La modification des prospects sera activée avec le pipeline CRM.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Lead $lead)
    {
        $this->ensureCompanyOwnsLead($lead);
        $lead->delete();

        return redirect()->route('leads.index')->with('success', 'Prospect archivé.');
    }

    private function ensureCompanyOwnsLead(Lead $lead): void
    {
        abort_unless($lead->company_id === $this->currentCompanyId(), 404);
    }

    private function currentCompanyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
