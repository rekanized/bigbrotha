@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | ONVIF Sweep')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Discovery')

@section('page_title', 'ONVIF sweep')

@section('page_lead', 'Start small with WS-Discovery: send a multicast probe, listen for ONVIF devices publishing themselves on the local network, and inspect the endpoints they return.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
    <a class="button button--primary" href="{{ route('live-wall.index') }}" wire:navigate>Live wall</a>
@endsection

@section('content')
    <section class="screen-grid">
        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">WS-Discovery probe</h2>
                    <p class="panel-copy">The sweep sends a multicast probe to 239.255.255.250:3702 for ONVIF NetworkVideoTransmitter devices and lists the responses it receives back.</p>
                </div>
            </div>

            <livewire:discovery.onvif-sweep />
        </section>
    </section>
@endsection