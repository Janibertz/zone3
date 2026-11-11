import Foundation

/// Was `/widget/{token}.json` liefert (`App\Services\Widget\WidgetPayload`).
/// Das Widget rechnet nur aus, welcher Tag heute ist — alle Zahlen kommen
/// vom Server, so wie auf „Heute“ in der App.
struct WidgetFeed: Codable {
    let v: Int
    let generatedAt: String
    let base: String
    let days: [Day]
    let suggestion: Item?
    let planStart: String?
    let race: Race?
    let week: Week

    struct Day: Codable {
        let date: String
        let items: [Item]
    }

    struct Item: Codable {
        let id: Int?
        let type: String
        let label: String
        let accent: String
        let photo: String
        let run: Bool
        let title: String
        let km: Double?
        let min: Int?
        let pace: String?
        let zone: String?
        let band: [Segment]
        let status: String
        let actual: Actual?
        let url: String
        /// Nur beim Vorschlag gesetzt.
        let date: String?

        var isRest: Bool { type == "rest" }
        var isPlanned: Bool { status == "planned" }
        var isDone: Bool { status == "completed" }
    }

    struct Segment: Codable {
        let f: Double
        let z: Int
        let w: Bool
    }

    struct Actual: Codable {
        let km: Double?
        let pace: String?
        let min: Int?
    }

    struct Race: Codable {
        let name: String
        let date: String
        let target: String?
        let open: Bool
        let week: Int?
        let weeks: Int?
    }

    struct Week: Codable {
        let monday: String
        let runKm: Double
        let plannedKm: Double?
    }

    static func decode(_ data: Data) -> WidgetFeed? {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try? decoder.decode(WidgetFeed.self, from: data)
    }
}

/// Holt die Daten und merkt sich die letzte Antwort, damit das Widget auch
/// ohne Netz den Stand von zuletzt zeigt.
enum FeedLoader {
    private static var cacheURL: URL? {
        guard let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first else { return nil }
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("zone3-widget.json")
    }

    static var isConnected: Bool { WidgetFeedStore.load() != nil }

    static func cached() -> WidgetFeed? {
        guard let url = cacheURL, let data = try? Data(contentsOf: url) else { return nil }
        return WidgetFeed.decode(data)
    }

    static func load() async -> WidgetFeed? {
        guard let address = WidgetFeedStore.load(), let url = URL(string: address) else { return nil }

        var request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 15)
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        if let (data, response) = try? await URLSession.shared.data(for: request),
           let http = response as? HTTPURLResponse {
            if http.statusCode == 200, let feed = WidgetFeed.decode(data) {
                if let cache = cacheURL { try? data.write(to: cache, options: .atomic) }
                return feed
            }
            // Token ungültig (neu erzeugt, Konto weg): nichts Altes mehr zeigen.
            if http.statusCode == 404 {
                if let cache = cacheURL { try? FileManager.default.removeItem(at: cache) }
                return nil
            }
        }
        return cached()
    }
}

/// Was an einem Tag zu sehen ist. Dieselbe Reihenfolge wie die
/// Heute-Karte in `routes/web.php`: zuerst ein offener Lauf oder eine
/// Radeinheit, sonst eine andere offene Einheit, sonst die erledigte.
struct DayView {
    /// Eine kommende Einheit: „Morgen“ für Sätze, „Do“ für die schmale Spalte.
    struct Upcoming {
        let day: String
        let short: String
        let item: WidgetFeed.Item
    }

    enum Kind {
        case planned, done, rest, suggestion, planStart, free, noPlan
    }

    let kind: Kind
    let hero: WidgetFeed.Item?
    /// Für Ruhetag und erledigt: was danach kommt.
    let following: Upcoming?
    let next: [Upcoming]
    let race: WidgetFeed.Race?
    let raceDays: Int?
    let planStartDays: Int?
    let planStartLabel: String?
    let week: WidgetFeed.Week?
    let base: String

    init(feed: WidgetFeed, now: Date) {
        let today = Fmt.dayString(now)
        let todays = feed.days.first(where: { $0.date == today })?.items ?? []

        let hero = todays.first(where: { $0.isPlanned && ($0.run || $0.label == "Rad") })
            ?? todays.first(where: { $0.isPlanned && !$0.isRest })
            ?? todays.first(where: { $0.isDone })
            ?? todays.first(where: { $0.isRest })

        var upcoming: [Upcoming] = todays
            .filter { $0.isPlanned && !$0.isRest && $0.url != hero?.url }
            .map { Upcoming(day: "Heute", short: "Heute", item: $0) }
        for day in feed.days where day.date > today {
            for item in day.items where !item.isRest && item.isPlanned {
                upcoming.append(Upcoming(day: Fmt.dayLabel(day.date, from: now), short: Fmt.shortDay(day.date), item: item))
            }
        }
        self.next = upcoming
        self.following = upcoming.first

        self.base = feed.base
        self.race = feed.race
        self.raceDays = feed.race.map { Fmt.daysBetween(today, $0.date) }
        if let start = feed.planStart, start > today {
            self.planStartDays = Fmt.daysBetween(today, start)
            self.planStartLabel = Fmt.dateLabel(start)
        } else {
            self.planStartDays = nil
            self.planStartLabel = nil
        }
        self.week = feed.week.monday == Fmt.dayString(Fmt.monday(of: now)) ? feed.week : nil

        if let hero {
            self.hero = hero
            self.kind = hero.isRest ? .rest : (hero.isDone ? .done : .planned)
        } else if let s = feed.suggestion, s.date == today {
            self.hero = s
            self.kind = .suggestion
        } else {
            self.hero = nil
            if planStartDays != nil {
                self.kind = .planStart
            } else if !feed.days.isEmpty {
                self.kind = .free
            } else {
                self.kind = .noPlan
            }
        }
    }

    var heroURL: URL? {
        URL(string: base + (hero?.url ?? "/dashboard"))
    }

    func url(_ item: WidgetFeed.Item) -> URL? {
        URL(string: base + item.url)
    }
}

/// Zahlen und Tage auf Deutsch, wie `useFormat.js`.
enum Fmt {
    private static let posix: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.calendar = Calendar(identifier: .gregorian)
        f.timeZone = .current
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    private static var calendar: Calendar {
        var c = Calendar(identifier: .gregorian)
        c.locale = Locale(identifier: "de_DE")
        c.firstWeekday = 2
        return c
    }

    static func dayString(_ date: Date) -> String { posix.string(from: date) }
    static func date(_ s: String) -> Date? { posix.date(from: s) }

    static func monday(of date: Date) -> Date {
        calendar.dateInterval(of: .weekOfYear, for: date)?.start ?? date
    }

    static func daysBetween(_ from: String, _ to: String) -> Int {
        guard let a = date(from), let b = date(to) else { return 0 }
        return calendar.dateComponents([.day], from: a, to: b).day ?? 0
    }

    /// „Heute“, „Morgen“, sonst der Wochentag kurz („Do“).
    static func dayLabel(_ day: String, from now: Date) -> String {
        let diff = daysBetween(dayString(now), day)
        if diff == 0 { return "Heute" }
        if diff == 1 { return "Morgen" }
        guard let d = date(day) else { return "" }
        let f = DateFormatter()
        f.locale = Locale(identifier: "de_DE")
        f.dateFormat = diff < 7 ? "EE" : "d.M."
        return f.string(from: d).replacingOccurrences(of: ".", with: diff < 7 ? "" : ".")
    }

    /// Kurz für die Zeilen: „Do“ statt „Morgen“, damit die Spalte schmal bleibt.
    static func shortDay(_ day: String) -> String {
        guard let d = date(day) else { return "" }
        let f = DateFormatter()
        f.locale = Locale(identifier: "de_DE")
        f.dateFormat = "EE"
        return f.string(from: d).replacingOccurrences(of: ".", with: "")
    }

    static func dateLabel(_ day: String) -> String {
        guard let d = date(day) else { return day }
        let f = DateFormatter()
        f.locale = Locale(identifier: "de_DE")
        f.dateFormat = "d.M."
        return f.string(from: d)
    }

    /// 10,5 · 8 · 24,8
    static func num(_ value: Double) -> String {
        let f = NumberFormatter()
        f.locale = Locale(identifier: "de_DE")
        f.minimumFractionDigits = 0
        f.maximumFractionDigits = 1
        return f.string(from: NSNumber(value: value)) ?? "\(value)"
    }

    /// 45 min · 1:40 h
    static func duration(_ min: Int) -> (value: String, unit: String) {
        if min < 90 { return ("\(min)", "min") }
        return ("\(min / 60):" + String(format: "%02d", min % 60), "h")
    }
}
