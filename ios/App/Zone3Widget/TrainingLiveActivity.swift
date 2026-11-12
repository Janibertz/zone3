import ActivityKit
import SwiftUI
import WidgetKit

/// Sperrbildschirm und Dynamic Island für `TrainingActivityAttributes`.
/// Aufbau wie im Mockup: Chip mit Typ und Zone, Titel, drei große Zahlen,
/// das Band in Zonenfarben, darunter eine Zeile, die sagt, was ein Tipp tut.
struct TrainingLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: TrainingActivityAttributes.self) { context in
            TrainingLockView(state: context.state)
                .activityBackgroundTint(Color(red: 28 / 255, green: 28 / 255, blue: 30 / 255).opacity(0.78))
                .activitySystemActionForegroundColor(.white)
                .widgetURL(URL(string: context.state.url))
        } dynamicIsland: { context in
            let s = context.state
            return DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    LiveChip(state: s).padding(.leading, 4)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    LiveWordmark().padding(.trailing, 4)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    VStack(alignment: .leading, spacing: 8) {
                        Text(s.title)
                            .font(Z.font(16, .bold))
                            .lineLimit(1)
                        LiveMetrics(metrics: s.metrics, size: 22)
                        if !s.band.isEmpty {
                            Band(segments: s.band.map { WidgetFeed.Segment(f: $0.f, z: $0.z, w: $0.w) }, height: 6)
                        }
                        if let foot = s.foot {
                            Text(foot)
                                .font(Z.font(11, .semibold))
                                .foregroundStyle(.white.opacity(0.7))
                                .lineLimit(1)
                        }
                    }
                    .padding(.horizontal, 4)
                    .padding(.top, 2)
                }
            } compactLeading: {
                HStack(spacing: 5) {
                    Circle().fill(s.done ? Z.success : Z.accent(s.accent)).frame(width: 9, height: 9)
                    Text(s.done ? "Erledigt" : s.label)
                        .font(Z.font(12, .bold))
                        .lineLimit(1)
                }
            } compactTrailing: {
                Text(s.metrics.first.map { "\($0.v) \($0.u)" } ?? "")
                    .font(Z.font(12, .bold))
                    .monospacedDigit()
                    .lineLimit(1)
            } minimal: {
                Circle().fill(s.done ? Z.success : Z.accent(s.accent)).frame(width: 10, height: 10)
            }
            .widgetURL(URL(string: s.url))
            .keylineTint(s.done ? Z.success : Z.accent(s.accent))
        }
    }
}

struct TrainingLockView: View {
    let state: TrainingActivityAttributes.ContentState

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                LiveChip(state: state)
                if state.done {
                    Text(state.name).font(Z.font(12, .bold)).foregroundStyle(.white.opacity(0.85))
                } else if let zone = state.zone {
                    Text("Zone \(zone)").font(Z.font(12, .bold)).foregroundStyle(.white.opacity(0.85))
                }
                Spacer(minLength: 4)
                LiveWordmark()
            }
            Text(state.title)
                .font(Z.font(16, .bold))
                .lineLimit(1)
            LiveMetrics(metrics: state.metrics, size: 22)
            if !state.band.isEmpty {
                Band(segments: state.band.map { WidgetFeed.Segment(f: $0.f, z: $0.z, w: $0.w) }, height: 6)
            }
            if let foot = state.foot {
                Text(foot)
                    .font(Z.font(11, .semibold))
                    .foregroundStyle(.white.opacity(0.7))
                    .lineLimit(1)
            }
        }
        .foregroundStyle(.white)
        .padding(14)
    }
}

/// „Tempolauf“ in Typfarbe, erledigt „✓ Erledigt“ in Grün — Farbe blass
/// hinterlegt, Schrift in der Farbe, wie im Mockup.
struct LiveChip: View {
    let state: TrainingActivityAttributes.ContentState

    var body: some View {
        let color = state.done ? Z.success : Z.accent(state.accent)
        Text(state.done ? "✓ Erledigt" : state.name)
            .font(Z.font(11, .bold))
            .lineLimit(1)
            .foregroundStyle(color)
            .padding(.horizontal, 8)
            .frame(height: 20)
            .background(color.opacity(0.2), in: Capsule())
    }
}

struct LiveWordmark: View {
    var body: some View {
        HStack(spacing: 0) {
            Text("Zone3").foregroundStyle(.white.opacity(0.6))
            Text(".").foregroundStyle(Z.accent)
        }
        .font(Z.font(12, .heavy))
    }
}

struct LiveMetrics: View {
    let metrics: [TrainingActivityAttributes.Metric]
    var size: CGFloat = 22

    var body: some View {
        HStack(spacing: 16) {
            ForEach(Array(metrics.prefix(3).enumerated()), id: \.offset) { _, m in
                Metric(value: m.v, unit: m.u, size: size)
            }
        }
    }
}
