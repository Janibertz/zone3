import SwiftUI
import WidgetKit

/// Farben und Schrift wie in der App (`resources/css/app.css`, dunkle
/// Variante, und Inter aus `@fontsource-variable/inter`).
enum Z {
    static let ground = Color(red: 11 / 255, green: 11 / 255, blue: 13 / 255)

    static let success = Color(red: 48 / 255, green: 209 / 255, blue: 88 / 255)
    static let warn    = Color(red: 255 / 255, green: 159 / 255, blue: 10 / 255)
    static let danger  = Color(red: 255 / 255, green: 69 / 255, blue: 58 / 255)
    static let info    = Color(red: 94 / 255, green: 92 / 255, blue: 230 / 255)
    static let accent  = Color(red: 10 / 255, green: 132 / 255, blue: 255 / 255)
    static let neutral = Color(red: 142 / 255, green: 142 / 255, blue: 147 / 255)

    static func accent(_ name: String) -> Color {
        switch name {
        case "success": return success
        case "warn":    return warn
        case "danger":  return danger
        case "info":    return info
        case "accent":  return accent
        default:        return neutral
        }
    }

    /// Zonenfarben wie die Rundenbalken: Z1 grau, Z2 grün, Z3 blau, Z4 orange, Z5 rot.
    static func zone(_ seg: WidgetFeed.Segment) -> Color {
        guard seg.w else { return .white.opacity(0.45) }
        switch seg.z {
        case 2: return success
        case 3: return accent
        case 4: return warn
        case 5: return danger
        default: return .white.opacity(0.45)
        }
    }

    enum Weight { case semibold, bold, heavy }

    static func font(_ size: CGFloat, _ weight: Weight = .heavy) -> Font {
        switch weight {
        case .semibold: return .custom("Inter-SemiBold", size: size)
        case .bold:     return .custom("Inter-Bold", size: size)
        case .heavy:    return .custom("Inter-ExtraBold", size: size)
        }
    }
}

/// Das Foto zur Art der Einheit, unten dunkel, damit Weiß darauf steht.
struct PhotoBackground: View {
    let photo: String
    var align: UnitPoint = .center
    @Environment(\.widgetRenderingMode) private var mode

    var body: some View {
        if mode == .fullColor {
            ZStack {
                Image(photo)
                    .resizable()
                    .scaledToFill()
                LinearGradient(stops: [
                    .init(color: .black.opacity(0.35), location: 0),
                    .init(color: .clear, location: 0.3),
                    .init(color: .black.opacity(0.25), location: 0.52),
                    .init(color: .black.opacity(0.9), location: 1),
                ], startPoint: .top, endPoint: .bottom)
            }
        } else {
            Z.ground
        }
    }
}

struct Chip: View {
    let text: String
    let color: Color
    var glass = false
    var size: CGFloat = 11

    var body: some View {
        Text(text)
            .font(Z.font(size, .bold))
            .lineLimit(1)
            .foregroundStyle(glass || color == Z.neutral ? Color.white : Color.black)
            .padding(.horizontal, 8)
            .frame(height: size + 9)
            .background(glass ? Color.white.opacity(0.22) : color, in: Capsule())
    }
}

/// Der Ablauf als Band in Zonenfarben.
struct Band: View {
    let segments: [WidgetFeed.Segment]
    var height: CGFloat = 4

    var body: some View {
        GeometryReader { geo in
            let gap: CGFloat = 2
            let total = segments.reduce(0) { $0 + $1.f }
            let usable = max(0, geo.size.width - gap * CGFloat(max(0, segments.count - 1)))
            HStack(spacing: gap) {
                ForEach(Array(segments.enumerated()), id: \.offset) { _, seg in
                    Rectangle()
                        .fill(Z.zone(seg))
                        .frame(width: total > 0 ? usable * seg.f / total : 0)
                }
            }
        }
        .frame(height: height)
        .clipShape(Capsule())
    }
}

/// Zahl groß, Einheit klein und grau daneben.
struct Metric: View {
    let value: String
    let unit: String
    var size: CGFloat = 17

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 2) {
            Text(value).font(Z.font(size)).tracking(-0.03 * size).monospacedDigit()
            Text(unit).font(Z.font(max(10, size * 0.62), .semibold)).foregroundStyle(.white.opacity(0.7))
        }
        .lineLimit(1)
    }
}

extension WidgetFeed.Item {
    /// „Tempo · Z4“, beim großen Widget „Tempo · Zone 4“.
    func chip(long: Bool = false) -> String {
        guard let zone, !isRest else { return label }
        return "\(label) · \(long ? "Zone " : "Z")\(zone)"
    }

    /// Bis zu drei Zahlen: Strecke, Dauer, Pace.
    func metrics(max count: Int = 3) -> [(String, String)] {
        var out: [(String, String)] = []
        if let km { out.append((Fmt.num(km), "km")) }
        if let min { let d = Fmt.duration(min); out.append((d.value, d.unit)) }
        if let pace, run { out.append((pace, "/km")) }
        return Array(out.prefix(count))
    }

    /// Erledigt: die echten Werte, Strecke und Pace (Rad: Dauer).
    func actualMetrics() -> [(String, String)] {
        guard let actual else { return metrics(max: 2) }
        var out: [(String, String)] = []
        if let km = actual.km, run || label != "Rad" { out.append((Fmt.num(km), "km")) }
        if let pace = actual.pace { out.append((pace, "/km")) }
        else if let min = actual.min { let d = Fmt.duration(min); out.append((d.value, d.unit)) }
        return Array(out.prefix(2))
    }

    /// Rechte Spalte in „Als Nächstes“: km bei Läufen, sonst Minuten.
    var rowValue: String {
        if let km, run { return "\(Fmt.num(km)) km" }
        if let min { let d = Fmt.duration(min); return "\(d.value) \(d.unit)" }
        return ""
    }
}
