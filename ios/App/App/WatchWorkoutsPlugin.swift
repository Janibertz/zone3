import Capacitor
import Foundation
import HealthKit
#if canImport(WorkoutKit)
import WorkoutKit
#endif

/// Zone3-Einheiten als geplante Trainings auf die Apple Watch (WorkoutKit).
///
/// Die Seite (zone3.run) schickt die Einheiten als JSON, so wie
/// `App\Services\Watch\WorkoutKitPayload` sie baut. Hier wird nichts
/// gerechnet: jede Zahl kommt vom Server, damit Garmin, COROS und die Watch
/// eine Einheit gleich verstehen.
///
/// Jede Einheit trägt eine UUID, die der Server aus Einheit und Inhalt
/// ableitet. Ändert sich die Einheit, ändert sich die UUID — darum reicht
/// der Vergleich der IDs, um zu wissen, was auf der Uhr veraltet ist.
/// `scheduledWorkouts` liefert nur, was diese App selbst eingeplant hat.
///
/// WorkoutKit gibt es erst ab iOS 17; die App läuft ab iOS 15. Darunter
/// meldet `isAvailable` false, und die Seite bietet nichts an.
@objc(WatchWorkoutsPlugin)
public class WatchWorkoutsPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "WatchWorkoutsPlugin"
    public let jsName = "WatchWorkouts"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "isAvailable", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "authorizationState", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "requestAuthorization", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "sync", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "schedule", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "removeAll", returnType: CAPPluginReturnPromise),
    ]

    /// Welche Zone3-Einheit hinter welcher UUID steht. Nur für `schedule`:
    /// eine einzeln geschickte Einheit ersetzt ihre ältere Fassung.
    private static let sessionMapKey = "zone3.watchWorkouts.sessions"

    @objc func isAvailable(_ call: CAPPluginCall) {
        #if canImport(WorkoutKit)
        if #available(iOS 17.0, *) {
            call.resolve(["available": WorkoutScheduler.isSupported])
            return
        }
        #endif
        call.resolve(["available": false])
    }

    @objc func authorizationState(_ call: CAPPluginCall) {
        #if canImport(WorkoutKit)
        if #available(iOS 17.0, *) {
            Task {
                let state = await WorkoutScheduler.shared.authorizationState
                call.resolve(["state": Self.label(state)])
            }
            return
        }
        #endif
        call.resolve(["state": "unavailable"])
    }

    @objc func requestAuthorization(_ call: CAPPluginCall) {
        #if canImport(WorkoutKit)
        if #available(iOS 17.0, *) {
            Task {
                let state = await WorkoutScheduler.shared.requestAuthorization()
                call.resolve(["state": Self.label(state)])
            }
            return
        }
        #endif
        call.resolve(["state": "unavailable"])
    }

    /// Gleicht die eingeplanten Trainings mit der Liste ab: was fehlt, wird
    /// eingeplant, was nicht mehr in der Liste steht, fliegt raus.
    /// Erledigte Trainings bleiben, vergangene Tage fasst der Abgleich nicht an.
    @objc func sync(_ call: CAPPluginCall) {
        #if canImport(WorkoutKit)
        if #available(iOS 17.0, *) {
            let incoming: [WatchWorkout]
            do {
                incoming = try Self.decode(call.getArray("workouts") ?? [])
            } catch {
                call.reject("Die Einheiten ließen sich nicht lesen: \(error.localizedDescription)")
                return
            }

            Task {
                let scheduler = WorkoutScheduler.shared
                guard await scheduler.authorizationState == .authorized else {
                    call.resolve(["state": Self.label(await scheduler.authorizationState), "scheduled": 0, "removed": 0, "kept": 0])
                    return
                }

                let wanted = Dictionary(incoming.map { ($0.id, $0) }, uniquingKeysWith: { a, _ in a })
                let today = Calendar.current.startOfDay(for: Date())
                var present = Set<UUID>()
                var removed = 0

                for existing in await scheduler.scheduledWorkouts {
                    if existing.complete { continue }
                    if let day = Calendar.current.date(from: existing.date), day < today { continue }

                    if let w = wanted[existing.plan.id], Self.sameDay(existing.date, w.dateComponents) {
                        present.insert(existing.plan.id)
                    } else {
                        await scheduler.remove(existing.plan, at: existing.date)
                        removed += 1
                    }
                }

                var scheduled = 0
                var failed: [String] = []
                let room = max(0, WorkoutScheduler.maxAllowedScheduledWorkoutCount - present.count)
                for w in incoming.filter({ !present.contains($0.id) }).prefix(room) {
                    do {
                        let plan = try w.plan()
                        await scheduler.schedule(plan, at: w.dateComponents)
                        Self.remember(session: w.session, id: w.id)
                        scheduled += 1
                    } catch {
                        failed.append(w.name)
                    }
                }

                call.resolve([
                    "state": "authorized",
                    "scheduled": scheduled,
                    "removed": removed,
                    "kept": present.count,
                    "failed": failed,
                ])
            }
            return
        }
        #endif
        call.resolve(["state": "unavailable", "scheduled": 0, "removed": 0, "kept": 0])
    }

    /// Eine einzelne Einheit (Knopf „Auf die Apple Watch“). Eine ältere
    /// Fassung derselben Einheit wird ersetzt, alles andere bleibt.
    @objc func schedule(_ call: CAPPluginCall) {
        #if canImport(WorkoutKit)
        if #available(iOS 17.0, *) {
            let workout: WatchWorkout
            do {
                guard let raw = call.getObject("workout"), let w = try Self.decode([raw]).first else {
                    call.reject("Keine Einheit übergeben.")
                    return
                }
                workout = w
            } catch {
                call.reject("Die Einheit ließ sich nicht lesen: \(error.localizedDescription)")
                return
            }

            Task {
                let scheduler = WorkoutScheduler.shared
                var state = await scheduler.authorizationState
                if state == .notDetermined {
                    state = await scheduler.requestAuthorization()
                }
                guard state == .authorized else {
                    call.resolve(["state": Self.label(state), "scheduled": false])
                    return
                }

                let map = Self.sessionMap()
                for existing in await scheduler.scheduledWorkouts where !existing.complete {
                    let sameSession = map[existing.plan.id.uuidString] == workout.session
                    if existing.plan.id == workout.id || sameSession {
                        await scheduler.remove(existing.plan, at: existing.date)
                    }
                }

                do {
                    await scheduler.schedule(try workout.plan(), at: workout.dateComponents)
                    Self.remember(session: workout.session, id: workout.id)
                    call.resolve(["state": "authorized", "scheduled": true])
                } catch {
                    call.reject("Die Watch hat die Einheit nicht angenommen: \(error.localizedDescription)")
                }
            }
            return
        }
        #endif
        call.resolve(["state": "unavailable", "scheduled": false])
    }

    /// Alles, was Zone3 eingeplant hat, von der Uhr nehmen (Abschalten im Profil).
    @objc func removeAll(_ call: CAPPluginCall) {
        #if canImport(WorkoutKit)
        if #available(iOS 17.0, *) {
            Task {
                await WorkoutScheduler.shared.removeAllWorkouts()
                UserDefaults.standard.removeObject(forKey: Self.sessionMapKey)
                call.resolve()
            }
            return
        }
        #endif
        call.resolve()
    }

    // MARK: - Hilfen

    private static func decode(_ raw: [Any]) throws -> [WatchWorkout] {
        let data = try JSONSerialization.data(withJSONObject: raw)
        return try JSONDecoder().decode([WatchWorkout].self, from: data)
    }

    private static func sameDay(_ a: DateComponents, _ b: DateComponents) -> Bool {
        a.year == b.year && a.month == b.month && a.day == b.day
    }

    private static func sessionMap() -> [String: Int] {
        UserDefaults.standard.dictionary(forKey: sessionMapKey) as? [String: Int] ?? [:]
    }

    private static func remember(session: Int?, id: UUID) {
        guard let session else { return }
        var map = sessionMap()
        map[id.uuidString] = session
        // Nicht ewig wachsen lassen: die Uhr hält höchstens 15 Trainings.
        if map.count > 60 { map = map.filter { $0.value == session } }
        UserDefaults.standard.set(map, forKey: sessionMapKey)
    }

    #if canImport(WorkoutKit)
    @available(iOS 17.0, *)
    private static func label(_ state: WorkoutScheduler.AuthorizationState) -> String {
        switch state {
        case .authorized: return "authorized"
        case .denied: return "denied"
        case .notDetermined: return "notDetermined"
        case .restricted: return "restricted"
        @unknown default: return "unknown"
        }
    }
    #endif
}

// MARK: - Das JSON vom Server

private struct WatchWorkout: Decodable {
    let id: UUID
    let session: Int?
    let date: String          // Y-m-d
    let name: String
    let activity: String      // running
    let location: String      // outdoor | indoor
    let warmup: WatchStep?
    let blocks: [WatchBlock]
    let cooldown: WatchStep?

    var dateComponents: DateComponents {
        let parts = date.split(separator: "-").compactMap { Int($0) }
        var c = DateComponents()
        c.calendar = Calendar.current
        if parts.count == 3 {
            c.year = parts[0]
            c.month = parts[1]
            c.day = parts[2]
        }
        return c
    }

    #if canImport(WorkoutKit)
    @available(iOS 17.0, *)
    func plan() throws -> WorkoutPlan {
        let custom = CustomWorkout(
            activity: activity == "running" ? .running : .other,
            location: location == "indoor" ? .indoor : .outdoor,
            displayName: name,
            warmup: warmup?.workoutStep(),
            blocks: blocks.map { $0.intervalBlock() },
            cooldown: cooldown?.workoutStep()
        )
        return WorkoutPlan(.custom(custom), id: id)
    }
    #endif
}

private struct WatchBlock: Decodable {
    let iterations: Int
    let steps: [WatchStep]

    #if canImport(WorkoutKit)
    @available(iOS 17.0, *)
    func intervalBlock() -> IntervalBlock {
        IntervalBlock(
            steps: steps.map { IntervalStep($0.purpose == "recovery" ? .recovery : .work, step: $0.workoutStep()) },
            iterations: max(1, iterations)
        )
    }
    #endif
}

private struct WatchStep: Decodable {
    let purpose: String?      // work | recovery (nur in Blöcken)
    let distance_m: Double?
    let seconds: Double?
    let speed: [Double]?      // [langsam, schnell] in m/s

    #if canImport(WorkoutKit)
    @available(iOS 17.0, *)
    func workoutStep() -> WorkoutStep {
        let goal: WorkoutGoal
        if let m = distance_m, m > 0 {
            goal = .distance(m, .meters)
        } else if let s = seconds, s > 0 {
            goal = .time(s, .seconds)
        } else {
            goal = .open
        }

        guard let speed, speed.count == 2, speed[0] > 0, speed[1] >= speed[0] else {
            return WorkoutStep(goal: goal)
        }

        let range = Measurement(value: speed[0], unit: UnitSpeed.metersPerSecond)...Measurement(value: speed[1], unit: UnitSpeed.metersPerSecond)
        return WorkoutStep(goal: goal, alert: SpeedRangeAlert(target: range, metric: .current))
    }
    #endif
}
