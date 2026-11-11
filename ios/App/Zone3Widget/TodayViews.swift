import SwiftUI
import WidgetKit

/// Der Foto-Teil von „Heute“ (klein, links im mittleren, oben im großen),
/// wie `TodayHero.vue`: Chip oben, Titel, Zahlen, Band.
struct HeroContent: View {
    let day: DayView
    var large = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 4) {
                chips
                Spacer(minLength: 4)
                if large, let race = day.race, let w = race.week, let n = race.weeks, day.kind != .planStart {
                    Text("Heute · Woche \(w) von \(n)")
                        .font(Z.font(12, .bold))
                        .foregroundStyle(.white.opacity(0.85))
                        .lineLimit(1)
                }
            }
            Spacer(minLength: 0)
            content
        }
        .padding(large ? EdgeInsets(top: 14, leading: 16, bottom: 14, trailing: 16) : EdgeInsets(top: 12, leading: 12, bottom: 12, trailing: 12))
        .foregroundStyle(.white)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }

    @ViewBuilder private var chips: some View {
        let size: CGFloat = large ? 12 : 11
        switch day.kind {
        case .planned:
            if let h = day.hero { Chip(text: h.chip(long: large), color: Z.accent(h.accent), size: size) }
        case .done:
            Chip(text: "Erledigt", color: Z.success, size: size)
        case .rest:
            Chip(text: "Ruhetag", color: Z.neutral, size: size)
        case .suggestion:
            if let h = day.hero { Chip(text: h.label, color: Z.accent(h.accent), size: size) }
            Chip(text: "Vorschlag", color: .white, glass: true, size: size)
        case .planStart:
            Chip(text: "Plan", color: .white, glass: true, size: size)
        case .free, .noPlan:
            Chip(text: "Heute", color: .white, glass: true, size: size)
        }
    }

    private var titleSize: CGFloat { large ? 26 : 17 }
    private var numberSize: CGFloat { large ? 22 : 17 }

    private func title(_ text: String) -> some View {
        Text(text)
            .font(Z.font(titleSize))
            .tracking(-0.035 * titleSize)
            .lineLimit(2)
            .minimumScaleFactor(0.8)
    }

    private func metrics(_ list: [(String, String)]) -> some View {
        HStack(spacing: large ? 18 : 10) {
            ForEach(Array(list.enumerated()), id: \.offset) { _, m in
                Metric(value: m.0, unit: m.1, size: numberSize)
            }
        }
        .padding(.top, 6)
    }

    private func line(_ text: String) -> some View {
        Text(text)
            .font(Z.font(large ? 13 : 11, .semibold))
            .foregroundStyle(.white.opacity(0.75))
            .lineLimit(1)
            .padding(.top, 6)
    }

    @ViewBuilder private var content: some View {
        switch day.kind {
        case .planned, .suggestion:
            if let h = day.hero {
                title(h.title)
                metrics(h.metrics(max: large ? 3 : 2))
                if !h.band.isEmpty {
                    Band(segments: h.band, height: large ? 5 : 4).padding(.top, large ? 10 : 8)
                } else if day.kind == .suggestion, let label = day.planStartLabel {
                    line("Plan startet am \(label)")
                }
            }
        case .done:
            if let h = day.hero {
                title(h.title)
                metrics(h.actualMetrics())
                if !large, let f = day.following { line("\(f.day) · \(f.item.title) \(f.item.rowValue)") }
            }
        case .rest, .free:
            title(day.kind == .rest ? "Erholung gehört dazu" : "Heute frei")
            if let f = day.following {
                line("\(f.day) · \(f.item.title)").padding(.top, 2)
                metrics(f.item.metrics(max: 2)).padding(.top, -4)
            }
        case .planStart:
            title("Plan startet in \(day.planStartDays ?? 0) Tagen")
            if let label = day.planStartLabel { line("Erste Einheit am \(label)") }
        case .noPlan:
            title("Noch kein Plan")
            line("In Zone3 ein Ziel eintragen")
        }
    }

    /// Welches Foto hinter „Heute“ steht.
    static func photo(for day: DayView) -> String {
        switch day.kind {
        case .planned, .done, .suggestion, .rest: return day.hero?.photo ?? "field"
        case .planStart: return "race"
        case .free: return "legs"
        case .noPlan: return "field"
        }
    }
}

/// Eine Zeile in „Als Nächstes“: Tag, Farbpunkt, Titel, km oder Minuten.
struct NextRow: View {
    let day: String
    let item: WidgetFeed.Item
    var large = false

    var body: some View {
        HStack(spacing: large ? 10 : 8) {
            Text(day)
                .font(Z.font(large ? 13 : 12, .semibold))
                .foregroundStyle(.white.opacity(0.55))
                .frame(width: large ? 26 : 22, alignment: .leading)
                .lineLimit(1)
            Circle().fill(Z.accent(item.accent)).frame(width: large ? 9 : 8, height: large ? 9 : 8)
            Text(large && !item.run ? "\(item.label) · \(item.title)" : (large ? item.title : shortTitle))
                .font(Z.font(large ? 14 : 13, .semibold))
                .lineLimit(1)
            Spacer(minLength: 4)
            Text(large ? largeValue : item.rowValue)
                .font(Z.font(large ? 14 : 13, .bold))
                .monospacedDigit()
                .lineLimit(1)
            if large, item.run, let min = item.min {
                let d = Fmt.duration(min)
                Text("\(d.value) \(d.unit)")
                    .font(Z.font(13, .semibold))
                    .foregroundStyle(.white.opacity(0.55))
                    .frame(width: 48, alignment: .trailing)
                    .lineLimit(1)
            }
        }
        .foregroundStyle(.white)
    }

    /// Im mittleren Widget ist die Spalte schmal: der Typ statt des langen Titels.
    private var shortTitle: String { item.label }

    private var largeValue: String {
        if item.run, let km = item.km { return "\(Fmt.num(km)) km" }
        if !item.run, let min = item.min { let d = Fmt.duration(min); return "\(d.value) \(d.unit)" }
        return ""
    }
}

struct RaceFooter: View {
    let race: WidgetFeed.Race
    let days: Int
    var large = false

    var body: some View {
        HStack(spacing: 4) {
            Circle().fill(Z.accent).frame(width: 6, height: 6)
            Text(large && race.target != nil ? "\(race.name) · Ziel \(race.target!)" : "\(race.name) · \(days) Tage")
                .font(Z.font(large ? 12 : 11, .semibold))
                .foregroundStyle(.white.opacity(large ? 0.7 : 0.55))
                .lineLimit(1)
            if large {
                Spacer(minLength: 4)
                HStack(alignment: .firstTextBaseline, spacing: 3) {
                    Text("\(days)").font(Z.font(13)).monospacedDigit()
                    Text("Tage").font(Z.font(13, .semibold)).foregroundStyle(.white.opacity(0.7))
                }
                .foregroundStyle(.white)
            }
        }
    }
}

struct WeekFooter: View {
    let week: WidgetFeed.Week

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 3) {
            Text(Fmt.num(week.runKm)).font(Z.font(11)).foregroundStyle(.white)
            Text(week.plannedKm.map { "von \(Fmt.num($0)) km diese Woche" } ?? "km diese Woche")
                .font(Z.font(11, .semibold))
                .foregroundStyle(.white.opacity(0.55))
        }
        .lineLimit(1)
    }
}

// MARK: - Größen

struct TodaySmall: View {
    let day: DayView

    var body: some View {
        HeroContent(day: day)
            .containerBackground(for: .widget) { PhotoBackground(photo: HeroContent.photo(for: day)) }
            .widgetURL(day.heroURL)
    }
}

struct TodayMedium: View {
    let day: DayView

    var body: some View {
        GeometryReader { geo in
            HStack(spacing: 0) {
                HeroContent(day: day)
                    .frame(width: geo.size.height)
                    .background(PhotoBackground(photo: HeroContent.photo(for: day)))
                    .clipped()

                VStack(alignment: .leading, spacing: 0) {
                    Text(day.kind == .planStart ? "Plan startet" : "Als Nächstes")
                        .font(Z.font(12, .bold))
                        .foregroundStyle(.white.opacity(0.55))
                        .padding(.bottom, 9)

                    if day.kind == .planStart, let n = day.planStartDays {
                        HStack(alignment: .firstTextBaseline, spacing: 4) {
                            Text("\(n)").font(Z.font(44)).tracking(-2.2).monospacedDigit()
                            Text("Tage").font(Z.font(13, .semibold)).foregroundStyle(.white.opacity(0.7))
                        }
                        .foregroundStyle(.white)
                        if let label = day.planStartLabel {
                            Text(label).font(Z.font(12, .semibold)).foregroundStyle(.white.opacity(0.7))
                        }
                    } else if day.next.isEmpty {
                        Text("Nichts geplant").font(Z.font(13, .semibold)).foregroundStyle(.white.opacity(0.7))
                    } else {
                        VStack(alignment: .leading, spacing: 9) {
                            ForEach(Array(day.next.prefix(3).enumerated()), id: \.offset) { _, n in
                                Link(destination: day.url(n.item) ?? URL(string: "https://zone3.run/dashboard")!) {
                                    NextRow(day: n.short, item: n.item)
                                }
                            }
                        }
                    }

                    Spacer(minLength: 0)
                    footer
                }
                .padding(EdgeInsets(top: 12, leading: 14, bottom: 12, trailing: 14))
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            }
        }
        .containerBackground(for: .widget) { Z.ground }
        .widgetURL(day.heroURL)
    }

    /// Erledigt: die Woche statt des Rennens (Mockup „Mittel: Zustände“).
    @ViewBuilder private var footer: some View {
        if day.kind == .done, let week = day.week {
            WeekFooter(week: week)
        } else if let race = day.race, let d = day.raceDays {
            RaceFooter(race: race, days: d)
        }
    }
}

struct TodayLarge: View {
    let day: DayView

    var body: some View {
        GeometryReader { geo in
            VStack(spacing: 0) {
                HeroContent(day: day, large: true)
                    .frame(height: geo.size.height * 0.49)
                    .frame(maxWidth: .infinity)
                    .background(PhotoBackground(photo: HeroContent.photo(for: day), align: .top))
                    .clipped()

                VStack(alignment: .leading, spacing: 0) {
                    HStack(alignment: .firstTextBaseline) {
                        Text(day.kind == .planStart ? "Plan startet" : "Als Nächstes")
                            .font(Z.font(13, .bold))
                            .foregroundStyle(.white.opacity(0.55))
                        Spacer()
                        if let week = day.week {
                            HStack(alignment: .firstTextBaseline, spacing: 3) {
                                Text(Fmt.num(week.runKm)).font(Z.font(12)).foregroundStyle(.white)
                                Text(week.plannedKm.map { "von \(Fmt.num($0)) km" } ?? "km")
                                    .font(Z.font(12, .semibold))
                                    .foregroundStyle(.white.opacity(0.55))
                            }
                        }
                    }
                    .padding(.bottom, 10)

                    if day.next.isEmpty {
                        Text(day.kind == .planStart ? "Erste Einheit am \(day.planStartLabel ?? "")" : "Nichts geplant")
                            .font(Z.font(14, .semibold))
                            .foregroundStyle(.white.opacity(0.7))
                    } else {
                        VStack(alignment: .leading, spacing: 9) {
                            ForEach(Array(day.next.prefix(4).enumerated()), id: \.offset) { _, n in
                                Link(destination: day.url(n.item) ?? URL(string: "https://zone3.run/dashboard")!) {
                                    NextRow(day: n.short, item: n.item, large: true)
                                }
                            }
                        }
                    }

                    Spacer(minLength: 0)

                    if let race = day.race, let d = day.raceDays {
                        Divider().overlay(Color.white.opacity(0.12)).padding(.bottom, 9)
                        RaceFooter(race: race, days: d, large: true)
                    }
                }
                .padding(EdgeInsets(top: 12, leading: 16, bottom: 12, trailing: 16))
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            }
        }
        .containerBackground(for: .widget) { Z.ground }
        .widgetURL(day.heroURL)
    }
}
