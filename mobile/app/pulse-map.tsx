import { useMemo, useState } from "react";
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { useRouter } from "expo-router";
import { useQuery } from "@tanstack/react-query";
import { Camera, GeoJSONSource, Layer, Map } from "@maplibre/maplibre-react-native";
import { civicConnectApi } from "../src/api/client";
import type { HeatmapPoint } from "../src/api/types";
import { CITY_PULSE_CATEGORIES, CITY_PULSE_MAP_STYLE, CITY_PULSE_STATUSES, cityPulseLabel, heatmapFeatureCollection, normalizeHeatmapPoint } from "../src/cityPulse";

function statusLabel(value: string): string {
  return value === "all" ? "All" : value === "open" ? "Open" : "Resolved";
}

export default function PulseMapScreen() {
  const router = useRouter();
  const query = useQuery({ queryKey: ["heatmap"], queryFn: civicConnectApi.getHeatmap, refetchInterval: 5000, refetchIntervalInBackground: false });
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("all");
  const [status, setStatus] = useState("all");
  const [selected, setSelected] = useState<HeatmapPoint | null>(null);
  const [resetToken, setResetToken] = useState(0);

  const allPoints = useMemo(() => (query.data?.data.points ?? []).map(normalizeHeatmapPoint).filter((point): point is HeatmapPoint => point !== null), [query.data]);
  const points = useMemo(() => allPoints.filter((point) => {
    const haystack = `${point.title} ${point.address} ${point.category}`.toLowerCase();
    const matchesSearch = !search.trim() || haystack.includes(search.trim().toLowerCase());
    const matchesCategory = category === "all" || point.category === category;
    const matchesStatus = status === "all" || (status === "open" ? point.status !== "resolved" : point.status === "resolved");
    return matchesSearch && matchesCategory && matchesStatus;
  }), [allPoints, category, search, status]);
  const region = points.length ? { latitude: points[0].lat, longitude: points[0].lng } : { latitude: 13.0827, longitude: 80.2707 };
  const featureCollection = useMemo(() => heatmapFeatureCollection(points), [points]);

  function selectIssue(issueId: number): void {
    setSelected(points.find((point) => point.id === issueId) ?? null);
  }

  if (query.isLoading) return <View style={styles.loading}><ActivityIndicator size="large" color="#67d5ff" /><Text style={styles.loadingText}>Loading the live city map…</Text></View>;
  if (query.isError || !query.data) return <View style={styles.loading}><Text style={styles.errorTitle}>City Pulse is unavailable</Text><Text style={styles.loadingText}>{query.error?.message ?? "The backend returned no heatmap data."}</Text><Pressable onPress={() => void query.refetch()} style={styles.retry}><Text style={styles.retryText}>Try again</Text></Pressable></View>;

  return (
    <View style={styles.root}>
      <Map style={StyleSheet.absoluteFill} mapStyle={CITY_PULSE_MAP_STYLE} attribution compass scaleBar androidView="texture">
        <Camera key={`${resetToken}-${points[0]?.id ?? "city"}`} initialViewState={{ center: [region.longitude, region.latitude], zoom: points.length ? 10.8 : 9.8 }} />
        <GeoJSONSource id="full-city-points" data={featureCollection} onPress={(event) => {
          const feature = event.nativeEvent.features?.[0];
          const issueId = Number(feature?.properties?.issueId);
          if (Number.isFinite(issueId) && issueId > 0) selectIssue(issueId);
        }}>
          <Layer id="full-city-heat" type="heatmap" source="full-city-points" maxzoom={14} paint={{
            "heatmap-weight": ["interpolate", ["linear"], ["get", "weight"], 0, 0.15, 1, 1],
            "heatmap-intensity": 1.15,
            "heatmap-radius": 32,
            "heatmap-opacity": 0.78,
            "heatmap-color": ["interpolate", ["linear"], ["heatmap-density"], 0, "rgba(57,214,162,0)", 0.35, "#39d6a2", 0.65, "#ffbd57", 1, "#ff5264"],
          } as never} />
          <Layer id="full-city-halos" type="circle" source="full-city-points" paint={{
            "circle-color": ["match", ["get", "band"], "red", "#ff5264", "yellow", "#ffbd57", "#39d6a2"],
            "circle-radius": ["interpolate", ["linear"], ["get", "count"], 1, 7, 20, 22],
            "circle-opacity": 0.92,
            "circle-stroke-color": "#ecfbff",
            "circle-stroke-width": 1.5,
          } as never} />
        </GeoJSONSource>
      </Map>

      <SafeAreaView pointerEvents="box-none" style={styles.safe}>
        <View pointerEvents="box-none" style={styles.topArea}>
          <View style={styles.topRow}>
            <Pressable onPress={() => router.back()} style={styles.iconButton} accessibilityLabel="Close full screen map"><Text style={styles.iconText}>‹</Text></Pressable>
            <View style={styles.heading}><Text style={styles.eyebrow}>CITY PULSE · LIVE</Text><Text style={styles.title}>{points.length} visible clusters</Text></View>
            <Pressable onPress={() => { setSelected(null); setResetToken((value) => value + 1); }} style={styles.iconButton} accessibilityLabel="Reset map view"><Text style={styles.iconText}>⌖</Text></Pressable>
          </View>
          <View style={styles.searchWrap}><Text style={styles.searchIcon}>⌕</Text><TextInput value={search} onChangeText={setSearch} placeholder="Search places or issues" placeholderTextColor="#8da3b2" style={styles.search} /></View>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.filterScroll}>
            {CITY_PULSE_STATUSES.map((value) => <Pressable key={value} onPress={() => setStatus(value)} style={[styles.filter, status === value && styles.filterSelected]}><Text style={[styles.filterText, status === value && styles.filterTextSelected]}>{statusLabel(value)}</Text></Pressable>)}
            {CITY_PULSE_CATEGORIES.filter((value) => value !== "all").map((value) => <Pressable key={value} onPress={() => setCategory(value)} style={[styles.filter, category === value && styles.filterSelected]}><Text style={[styles.filterText, category === value && styles.filterTextSelected]}>{cityPulseLabel(value)}</Text></Pressable>)}
          </ScrollView>
        </View>

        <View pointerEvents="box-none" style={styles.bottomArea}>
          {selected ? <Pressable onPress={() => router.push(`/issue/${selected.id}`)} style={styles.selectedCard}><View style={styles.selectedHeader}><Text style={styles.selectedCategory}>{cityPulseLabel(selected.category)}</Text><Text style={styles.selectedAction}>Open details →</Text></View><Text style={styles.selectedTitle}>{selected.title}</Text><Text style={styles.selectedMeta}>{selected.address} · {selected.count} reports · {selected.status === "resolved" ? "Resolved" : "Open"}</Text></Pressable> : <View style={styles.hint}><Text style={styles.hintText}>Drag to explore · pinch to zoom · tap a signal to inspect it</Text></View>}
          <Text style={styles.attribution}>Map tiles © OpenStreetMap contributors © CARTO · refreshed every 5 seconds</Text>
        </View>
      </SafeAreaView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: "#08111b" },
  safe: { flex: 1, justifyContent: "space-between" },
  topArea: { paddingHorizontal: 14, gap: 10 },
  topRow: { flexDirection: "row", alignItems: "center", gap: 10 },
  iconButton: { width: 42, height: 42, alignItems: "center", justifyContent: "center", borderRadius: 13, borderWidth: 1, borderColor: "rgba(255,255,255,.18)", backgroundColor: "rgba(8,17,27,.86)" },
  iconText: { color: "#edf7fb", fontSize: 28, lineHeight: 30 },
  heading: { flex: 1, paddingHorizontal: 2 },
  eyebrow: { color: "#67d5ff", fontSize: 10, fontWeight: "900", letterSpacing: 1.1 },
  title: { color: "#edf7fb", fontSize: 18, fontWeight: "900", marginTop: 2 },
  searchWrap: { flexDirection: "row", alignItems: "center", minHeight: 48, paddingHorizontal: 12, borderRadius: 13, borderWidth: 1, borderColor: "rgba(255,255,255,.18)", backgroundColor: "rgba(8,17,27,.86)" },
  searchIcon: { color: "#67d5ff", fontSize: 24, marginRight: 7 },
  search: { flex: 1, color: "#edf7fb", paddingVertical: 10 },
  filterScroll: { gap: 7, paddingVertical: 1 },
  filter: { paddingVertical: 8, paddingHorizontal: 11, borderRadius: 999, borderWidth: 1, borderColor: "rgba(255,255,255,.18)", backgroundColor: "rgba(8,17,27,.86)" },
  filterSelected: { backgroundColor: "#17647f", borderColor: "#67d5ff" },
  filterText: { color: "#b3c5cf", fontSize: 11, fontWeight: "800" },
  filterTextSelected: { color: "#fff" },
  bottomArea: { paddingHorizontal: 14, paddingBottom: 8, gap: 8 },
  selectedCard: { padding: 15, borderRadius: 18, borderWidth: 1, borderColor: "rgba(103,213,255,.55)", backgroundColor: "rgba(8,17,27,.9)" },
  selectedHeader: { flexDirection: "row", justifyContent: "space-between", gap: 8 },
  selectedCategory: { color: "#67d5ff", fontSize: 11, fontWeight: "900", textTransform: "uppercase" },
  selectedAction: { color: "#7ef0c5", fontSize: 11, fontWeight: "900" },
  selectedTitle: { color: "#edf7fb", fontSize: 17, fontWeight: "900", marginTop: 5 },
  selectedMeta: { color: "#b3c5cf", fontSize: 12, lineHeight: 18, marginTop: 4 },
  hint: { alignSelf: "center", paddingVertical: 9, paddingHorizontal: 13, borderRadius: 999, backgroundColor: "rgba(8,17,27,.82)" },
  hintText: { color: "#d3e2e8", fontSize: 11, fontWeight: "800" },
  attribution: { color: "#c2d0d6", fontSize: 10, textAlign: "center", textShadowColor: "#08111b", textShadowRadius: 3 },
  loading: { flex: 1, alignItems: "center", justifyContent: "center", gap: 12, padding: 24, backgroundColor: "#08111b" },
  loadingText: { color: "#b3c5cf", textAlign: "center" },
  errorTitle: { color: "#edf7fb", fontSize: 20, fontWeight: "900" },
  retry: { paddingVertical: 11, paddingHorizontal: 16, borderRadius: 12, backgroundColor: "#17647f" },
  retryText: { color: "#fff", fontWeight: "900" },
});
