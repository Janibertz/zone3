#!/usr/bin/env python3
"""
Garmin Connect microservice — handles auth + workout push via garth.
Runs on 127.0.0.1:8001, only reachable internally from the PHP app.
"""
import os
import json
import math
from flask import Flask, request, jsonify
import garth

app = Flask(__name__)

# ── Helpers ───────────────────────────────────────────────────────────────────

def pace_to_seconds(pace: str | None) -> int | None:
    """Convert 'M:SS' pace string to seconds/km, or None."""
    if not pace:
        return None
    try:
        parts = pace.split(':')
        return int(parts[0]) * 60 + int(parts[1])
    except Exception:
        return None


def build_workout_payload(session: dict) -> dict:
    """Build Garmin Connect workout JSON from a Zone3 training session."""
    dist_km     = session.get('distance_km') or 5
    pace_target = session.get('pace_target')
    stype       = session.get('type', 'easy_run')

    pace_sec    = pace_to_seconds(pace_target)
    easy_sec    = (pace_sec + 60) if pace_sec else None

    configs = {
        'easy_run':  {'wF': 0.10, 'wMax': 1.0, 'cF': 0.10, 'cMax': 1.0},
        'tempo_run': {'wF': 0.25, 'wMax': 2.0, 'cF': 0.12, 'cMax': 1.0},
        'interval':  {'wF': 0.20, 'wMax': 2.0, 'cF': 0.10, 'cMax': 1.0},
        'long_run':  {'wF': 0.05, 'wMax': 1.0, 'cF': 0.05, 'cMax': 1.0},
        'race_prep': {'wF': 0.30, 'wMax': 2.0, 'cF': 0.15, 'cMax': 1.0},
    }
    cfg = configs.get(stype, configs['easy_run'])

    warmup_km   = round(min(cfg['wMax'], dist_km * cfg['wF']), 2)
    cooldown_km = round(min(cfg['cMax'], dist_km * cfg['cF']), 2)
    main_km     = round(max(0.0, dist_km - warmup_km - cooldown_km), 2)

    steps = []
    order = 1

    def pace_target_block(pace_s):
        if pace_s and pace_s > 0:
            return {
                'targetType':     {'workoutTargetTypeId': 6, 'workoutTargetTypeKey': 'pace.zone'},
                'targetValueOne': int(pace_s * 0.9),
                'targetValueTwo': int(pace_s * 1.1),
            }
        return {'targetType': {'workoutTargetTypeId': 1, 'workoutTargetTypeKey': 'no.target'}}

    def distance_step(ord_, type_key, km, pace_s):
        type_map = {
            'warmup':   {'stepTypeId': 3, 'stepTypeKey': 'warmup'},
            'interval': {'stepTypeId': 1, 'stepTypeKey': 'interval'},
            'cooldown': {'stepTypeId': 2, 'stepTypeKey': 'cooldown'},
        }
        t = type_map.get(type_key, type_map['interval'])
        step = {
            'stepOrder':          ord_,
            'stepType':           t,
            'endCondition':       {'conditionTypeId': 3, 'conditionTypeKey': 'distance'},
            'endConditionValue':  int(round(km * 1000)),
        }
        step.update(pace_target_block(pace_s))
        return step

    if warmup_km > 0:
        steps.append(distance_step(order, 'warmup', warmup_km, easy_sec))
        order += 1
    if main_km > 0:
        steps.append(distance_step(order, 'interval', main_km, pace_sec))
        order += 1
    if cooldown_km > 0:
        steps.append(distance_step(order, 'cooldown', cooldown_km, easy_sec))

    duration_secs = int((session.get('duration_min') or 0) * 60) or None

    return {
        'workoutName':             session.get('title', 'Training'),
        'description':             session.get('description', ''),
        'sport':                   {'sportType': {'sportTypeId': 1, 'sportTypeKey': 'running'}},
        'estimatedDistanceUnit':   {'unitKey': 'kilometer'},
        'estimatedDurationInSecs': duration_secs,
        'workoutSegments': [{
            'segmentOrder': 1,
            'sportType':    {'sportTypeId': 1, 'sportTypeKey': 'running'},
            'workoutSteps': steps,
        }],
    }


def get_garth_client(email: str, password: str) -> garth.Client:
    """Return an authenticated garth client."""
    client = garth.Client()
    client.login(email, password)
    return client


# ── Routes ────────────────────────────────────────────────────────────────────

@app.route('/health', methods=['GET'])
def health():
    return jsonify({'ok': True})


@app.route('/test', methods=['POST'])
def test_connection():
    data = request.get_json()
    email    = data.get('email', '')
    password = data.get('password', '')
    try:
        client = get_garth_client(email, password)
        profile = client.connectapi('/userprofile-service/userprofile/personal-information')
        return jsonify({'ok': True, 'display_name': profile.get('displayName', '')})
    except Exception as e:
        return jsonify({'ok': False, 'error': str(e)}), 400


@app.route('/push-session', methods=['POST'])
def push_session():
    """Upload a single training session to Garmin Connect + schedule it."""
    data     = request.get_json()
    email    = data.get('email', '')
    password = data.get('password', '')
    session  = data.get('session', {})

    if not email or not password or not session:
        return jsonify({'ok': False, 'error': 'Missing fields'}), 400

    if session.get('type') == 'rest':
        return jsonify({'ok': True, 'skipped': True})

    try:
        client  = get_garth_client(email, password)
        payload = build_workout_payload(session)

        # Upload workout
        created = client.connectapi(
            '/workout-service/workout',
            method='POST',
            json=payload,
        )
        workout_id = created.get('workoutId')
        if not workout_id:
            return jsonify({'ok': False, 'error': 'No workoutId returned', 'response': created}), 500

        # Schedule on the planned date
        planned_date = session.get('planned_date', '')
        if planned_date:
            client.connectapi(
                f'/workout-service/schedule/{workout_id}',
                method='POST',
                json={'date': planned_date},
            )

        return jsonify({'ok': True, 'workout_id': workout_id})

    except Exception as e:
        return jsonify({'ok': False, 'error': str(e)}), 500


@app.route('/push-plan', methods=['POST'])
def push_plan():
    """Upload all sessions of a plan to Garmin Connect."""
    data     = request.get_json()
    email    = data.get('email', '')
    password = data.get('password', '')
    sessions = data.get('sessions', [])

    if not email or not password:
        return jsonify({'ok': False, 'error': 'Missing credentials'}), 400

    try:
        client = get_garth_client(email, password)
    except Exception as e:
        return jsonify({'ok': False, 'error': f'Login failed: {e}'}), 401

    results = []
    for session in sessions:
        if session.get('type') == 'rest':
            results.append({'title': session.get('title'), 'skipped': True})
            continue
        try:
            payload    = build_workout_payload(session)
            created    = client.connectapi('/workout-service/workout', method='POST', json=payload)
            workout_id = created.get('workoutId')
            if workout_id and session.get('planned_date'):
                client.connectapi(
                    f'/workout-service/schedule/{workout_id}',
                    method='POST',
                    json={'date': session['planned_date']},
                )
            results.append({'title': session.get('title'), 'ok': True, 'workout_id': workout_id})
        except Exception as e:
            results.append({'title': session.get('title'), 'ok': False, 'error': str(e)})

    all_ok = all(r.get('ok', r.get('skipped', False)) for r in results)
    return jsonify({'ok': all_ok, 'results': results})


if __name__ == '__main__':
    port = int(os.environ.get('GARMIN_SERVICE_PORT', 8001))
    app.run(host='127.0.0.1', port=port, debug=False)
