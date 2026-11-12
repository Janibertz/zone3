import SwiftUI
import WidgetKit

// MARK: - Zeitleiste

struct FeedEntry: TimelineEntry {
    let date: Date
    let feed: WidgetFeed?
    /// Hat die App dem Widget schon den Zugang gegeben?
    let connected: Bool
}

/// Lädt die Daten und legt Einträge für jetzt und die nächsten zwei
/// Mitternachten an: so springt das Widget auf den neuen Tag, auch wenn
/// iOS es gerade nicht neu laden lässt. Neu geladen wird nach 30 Minuten
/// (iOS entscheidet, wann genau) und immer, wenn die App sich öffnet.
struct FeedProvider: TimelineProvider {
    func placeholder(in context: Context) -> FeedEntry {
        FeedEntry(date: Date(), feed: Sample.feed, connected: true)
    }

    func getSnapshot(in context: Context, completion: @escaping (FeedEntry) -> Void) {
        if context.isPreview {
            completion(FeedEntry(date: Date(), feed: Sample.feed, connected: true))
            return
        }
        completion(FeedEntry(date: Date(), feed: FeedLoader.cached() ?? Sample.feed, connected: FeedLoader.isConnected))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<FeedEntry>) -> Void) {
        Task {
            let feed = await FeedLoader.load()
            let connected = FeedLoader.isConnected
            let now = Date()
            let cal = Calendar.current
            let midnight = cal.startOfDay(for: cal.date(byAdding: .day, value: 1, to: now) ?? now)
            let dates = [now, midnight.addingTimeInterval(5), midnight.addingTimeInterval(86_400 + 5)]
            let entries = dates.map { FeedEntry(date: $0, feed: feed, connected: connected) }
            completion(Timeline(entries: entries, policy: .after(now.addingTimeInterval(30 * 60))))
        }
    }
}

// MARK: - Heute

struct TodayEntryView: View {
    let entry: FeedEntry
    @Environment(\.widgetFamily) private var family

    var body: some View {
        if let feed = entry.feed {
            let day = DayView(feed: feed, now: entry.date)
            switch family {
            case .systemMedium: TodayMedium(day: day)
            case .systemLarge: TodayLarge(day: day)
            case .accessoryRectangular: TodayRectangular(day: day)
            case .accessoryInline: TodayInline(day: day)
            default: TodaySmall(day: day)
            }
        } else {
            NotConnected(connected: entry.connected)
        }
    }
}

struct TodayWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "Zone3Today", provider: FeedProvider()) { entry in
            TodayEntryView(entry: entry)
        }
        .configurationDisplayName("Heute")
        .description("Deine Einheit von heute und was als Nächstes kommt.")
        .supportedFamilies([.systemSmall, .systemMedium, .systemLarge, .accessoryRectangular, .accessoryInline])
        .contentMarginsDisabled()
    }
}

// MARK: - Rennen

struct RaceEntryView: View {
    let entry: FeedEntry
    @Environment(\.widgetFamily) private var family

    var body: some View {
        if let feed = entry.feed {
            let day = DayView(feed: feed, now: entry.date)
            switch family {
            case .accessoryCircular: RaceCircular(day: day)
            default: RaceSmall(day: day)
            }
        } else {
            NotConnected(connected: entry.connected)
        }
    }
}

struct RaceWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "Zone3Race", provider: FeedProvider()) { entry in
            RaceEntryView(entry: entry)
        }
        .configurationDisplayName("Rennen")
        .description("Die Tage bis zu deinem Rennen.")
        .supportedFamilies([.systemSmall, .accessoryCircular])
        .contentMarginsDisabled()
    }
}

// MARK: - Woche

struct WeekWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "Zone3Week", provider: FeedProvider()) { entry in
            WeekEntryView(entry: entry)
        }
        .configurationDisplayName("Woche")
        .description("Gelaufene gegen geplante Kilometer dieser Woche.")
        .supportedFamilies([.accessoryCircular])
    }
}

struct WeekEntryView: View {
    let entry: FeedEntry

    var body: some View {
        if let feed = entry.feed {
            WeekCircular(day: DayView(feed: feed, now: entry.date))
        } else {
            NotConnected(connected: entry.connected)
        }
    }
}

@main
struct Zone3WidgetBundle: WidgetBundle {
    var body: some Widget {
        TodayWidget()
        RaceWidget()
        WeekWidget()
        TrainingLiveActivity()
    }
}

// MARK: - Rennen klein

struct RaceSmall: View {
    let day: DayView

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let race = day.race {
                Text(race.name).font(Z.font(12, .bold)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
                if let target = race.target {
                    Text("Ziel \(target)").font(Z.font(11, .semibold)).foregroundStyle(.white.opacity(0.65)).padding(.top, 1)
                }
                Spacer(minLength: 0)
                if race.open, let w = race.week, let n = race.weeks {
                    Text("\(w)").font(Z.font(46)).tracking(-2.3).monospacedDigit()
                    Text("von \(n) Wochen im Block").font(Z.font(12, .semibold)).foregroundStyle(.white.opacity(0.75)).padding(.top, 2)
                } else {
                    Text("\(day.raceDays ?? 0)").font(Z.font(46)).tracking(-2.3).monospacedDigit().minimumScaleFactor(0.6)
                    Text(subline(race)).font(Z.font(12, .semibold)).foregroundStyle(.white.opacity(0.75)).padding(.top, 2).lineLimit(1)
                }
            } else {
                Text("Kein Rennen").font(Z.font(17)).tracking(-0.6)
                Spacer(minLength: 0)
                Text("In Zone3 ein Rennen eintragen").font(Z.font(12, .semibold)).foregroundStyle(.white.opacity(0.75))
            }
        }
        .foregroundStyle(.white)
        .padding(12)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .containerBackground(for: .widget) { PhotoBackground(photo: "race") }
        .widgetURL(URL(string: day.base + "/events"))
    }

    private func subline(_ race: WidgetFeed.Race) -> String {
        if let start = day.planStartLabel { return "Tage · Plan ab \(start)" }
        if let w = race.week, let n = race.weeks { return "Tage · Woche \(w) von \(n)" }
        return "Tage"
    }
}

// MARK: - Sperrbildschirm

/// Eckig: die Einheit von heute in drei Zeilen. iOS färbt selbst.
struct TodayRectangular: View {
    let day: DayView

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 3) {
                Image(systemName: icon).font(.system(size: 10, weight: .bold))
                Text(header).font(Z.font(12, .bold))
            }
            .opacity(0.75)
            Text(title).font(Z.font(15)).lineLimit(1).widgetAccentable()
            Text(numbers).font(Z.font(13, .semibold)).opacity(0.8).lineLimit(1).monospacedDigit()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .containerBackground(for: .widget) { Color.clear }
        .widgetURL(day.heroURL)
    }

    private var icon: String {
        switch day.kind {
        case .done: return "checkmark"
        case .rest, .free: return "moon.zzz.fill"
        default: return "bolt.fill"
        }
    }

    private var header: String {
        switch day.kind {
        case .planned: return "Heute · \(day.hero?.label ?? "")"
        case .done: return "Erledigt · \(day.hero?.label ?? "")"
        case .suggestion: return "Vorschlag · \(day.hero?.label ?? "")"
        case .rest: return "Heute · Ruhetag"
        case .free: return "Heute frei"
        case .planStart: return "Plan startet"
        case .noPlan: return "Zone3"
        }
    }

    private var title: String {
        switch day.kind {
        case .planned, .done, .suggestion: return day.hero?.title ?? ""
        case .rest, .free: return day.following.map { "\($0.day): \($0.item.title)" } ?? "Erholung"
        case .planStart: return "in \(day.planStartDays ?? 0) Tagen"
        case .noPlan: return "Noch kein Plan"
        }
    }

    private var numbers: String {
        let list: [(String, String)]
        switch day.kind {
        case .done: list = day.hero?.actualMetrics() ?? []
        case .planned, .suggestion: list = day.hero?.metrics(max: 2) ?? []
        case .rest, .free: list = day.following?.item.metrics(max: 2) ?? []
        case .planStart: return day.planStartLabel ?? ""
        case .noPlan: return ""
        }
        return list.map { $0.1 == "/km" ? "\($0.0)/km" : "\($0.0) \($0.1)" }.joined(separator: " · ")
    }
}

/// In der Datumszeile über der Uhr: „Tempo · 11,4 km“.
struct TodayInline: View {
    let day: DayView

    var body: some View {
        Group {
            switch day.kind {
            case .planned, .suggestion:
                Label(text(day.hero), systemImage: "bolt.fill")
            case .done:
                Label("Erledigt · \(day.hero?.label ?? "")", systemImage: "checkmark")
            case .rest, .free:
                Label(day.following.map { "\($0.day) \(text($0.item))" } ?? "Ruhetag", systemImage: "moon.zzz.fill")
            case .planStart:
                Label("Plan in \(day.planStartDays ?? 0) Tagen", systemImage: "calendar")
            case .noPlan:
                Text("Zone3")
            }
        }
        .containerBackground(for: .widget) { Color.clear }
        .widgetURL(day.heroURL)
    }

    private func text(_ item: WidgetFeed.Item?) -> String {
        guard let item else { return "" }
        return [item.label, item.rowValue].filter { !$0.isEmpty }.joined(separator: " · ")
    }
}

/// Ring mit Zahl in der Mitte.
struct RingGauge: View {
    let value: Double
    let big: String
    let small: String
    /// Countdown: offener Bogen unten wie bei Apple.
    var open = false

    var body: some View {
        ZStack {
            AccessoryWidgetBackground()
            Circle()
                .trim(from: 0, to: open ? 0.75 : 1)
                .stroke(Color.white.opacity(0.25), style: StrokeStyle(lineWidth: 5, lineCap: .round))
                .rotationEffect(.degrees(open ? 135 : -90))
                .padding(4)
            Circle()
                .trim(from: 0, to: max(0.02, min(1, value)) * (open ? 0.75 : 1))
                .stroke(Color.white, style: StrokeStyle(lineWidth: 5, lineCap: .round))
                .rotationEffect(.degrees(open ? 135 : -90))
                .padding(4)
                .widgetAccentable()
            VStack(spacing: -1) {
                Text(big).font(Z.font(big.count > 3 ? 16 : 20)).monospacedDigit().minimumScaleFactor(0.6)
                Text(small).font(Z.font(10, .bold)).opacity(0.75)
            }
            .padding(.horizontal, 8)
        }
    }
}

struct RaceCircular: View {
    let day: DayView

    var body: some View {
        Group {
            if day.race != nil, let days = day.raceDays {
                // Der Bogen füllt sich, je näher das Rennen kommt (über ein Jahr: leer).
                RingGauge(value: 1 - Double(min(days, 365)) / 365, big: "\(days)", small: "Tage", open: true)
            } else {
                RingGauge(value: 0, big: "–", small: "Rennen", open: true)
            }
        }
        .containerBackground(for: .widget) { Color.clear }
        .widgetURL(URL(string: day.base + "/events"))
    }
}

struct WeekCircular: View {
    let day: DayView

    var body: some View {
        let run = day.week?.runKm ?? 0
        let planned = day.week?.plannedKm
        RingGauge(
            value: planned.map { $0 > 0 ? run / $0 : 0 } ?? 0,
            big: Fmt.num(run),
            small: planned.map { "von \(Fmt.num($0))" } ?? "km"
        )
        .containerBackground(for: .widget) { Color.clear }
        .widgetURL(URL(string: day.base + "/activities"))
    }
}

// MARK: - Ohne Verbindung

struct NotConnected: View {
    let connected: Bool
    @Environment(\.widgetFamily) private var family

    var body: some View {
        Group {
            switch family {
            case .accessoryInline:
                Text("Zone3 öffnen")
            case .accessoryCircular:
                ZStack { AccessoryWidgetBackground(); Text("3.").font(Z.font(22)) }
            case .accessoryRectangular:
                VStack(alignment: .leading) {
                    Text("Zone3").font(Z.font(15))
                    Text(connected ? "Gerade keine Verbindung" : "App öffnen zum Verbinden").font(Z.font(12, .semibold)).opacity(0.8)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            default:
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 0) {
                        Text("Zone3").font(Z.font(20))
                        Text(".").font(Z.font(20)).foregroundStyle(Z.accent)
                    }
                    Spacer(minLength: 0)
                    Text(connected ? "Gerade keine Verbindung. Gleich noch mal." : "Öffne Zone3 einmal, dann steht hier deine Einheit.")
                        .font(Z.font(13, .semibold))
                        .foregroundStyle(.white.opacity(0.8))
                }
                .foregroundStyle(.white)
                .padding(14)
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            }
        }
        .containerBackground(for: .widget) { family.isAccessory ? AnyView(Color.clear) : AnyView(Z.ground) }
        .widgetURL(URL(string: "https://zone3.run/dashboard"))
    }
}

extension WidgetFamily {
    var isAccessory: Bool {
        self == .accessoryInline || self == .accessoryCircular || self == .accessoryRectangular
    }
}

// MARK: - Vorschau in der Widget-Galerie

enum Sample {
    static var feed: WidgetFeed {
        let today = Date()
        let d = { (n: Int) in Fmt.dayString(Calendar.current.date(byAdding: .day, value: n, to: today) ?? today) }
        let json = """
        {"v":1,"generated_at":"","base":"https://zone3.run","plan_start":null,
         "race":{"name":"HM Hannover","date":"\(d(152))","target":"1:25","open":false,"week":3,"weeks":22},
         "week":{"monday":"\(Fmt.dayString(Fmt.monday(of: today)))","run_km":24.8,"planned_km":46},
         "suggestion":null,
         "days":[
          {"date":"\(d(0))","items":[{"id":1,"type":"tempo_run","label":"Tempo","accent":"warn","photo":"street","run":true,"title":"Schwelle 3×10 min","km":11.4,"min":58,"pace":"4:12","zone":"4","status":"planned","actual":null,"url":"/dashboard",
            "band":[{"f":12,"z":1,"w":false},{"f":10,"z":4,"w":true},{"f":2,"z":0,"w":false},{"f":10,"z":4,"w":true},{"f":2,"z":0,"w":false},{"f":10,"z":4,"w":true},{"f":10,"z":1,"w":false}]}]},
          {"date":"\(d(1))","items":[{"id":2,"type":"easy_run","label":"Locker","accent":"success","photo":"field","run":true,"title":"Locker mit Steigerungen","km":8,"min":45,"pace":"5:35","zone":"2","status":"planned","actual":null,"url":"/dashboard","band":[]}]},
          {"date":"\(d(2))","items":[{"id":3,"type":"strength","label":"Kraft","accent":"warn","photo":"legs","run":false,"title":"Beine und Rumpf","km":null,"min":30,"pace":null,"zone":null,"status":"planned","actual":null,"url":"/dashboard","band":[]}]},
          {"date":"\(d(3))","items":[{"id":4,"type":"cross_training","label":"Rad","accent":"info","photo":"field","run":false,"title":"Lockeres Rad","km":null,"min":60,"pace":null,"zone":"2","status":"planned","actual":null,"url":"/dashboard","band":[]}]},
          {"date":"\(d(4))","items":[{"id":5,"type":"long_run","label":"Lang","accent":"info","photo":"long","run":true,"title":"Langer Lauf","km":18,"min":100,"pace":"5:30","zone":"2","status":"planned","actual":null,"url":"/dashboard","band":[]}]}
         ]}
        """
        return WidgetFeed.decode(Data(json.utf8))!
    }
}
