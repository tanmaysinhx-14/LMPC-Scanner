import { useEffect, useState } from "react";
import { ActivityIndicator, Alert, Image, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from "react-native";
import * as ImagePicker from "expo-image-picker";
import * as Location from "expo-location";
import { useQueryClient } from "@tanstack/react-query";
import { civicConnectApi, type UploadAsset } from "../../src/api/client";
import type { AiAnalysis } from "../../src/api/types";
import { useAuth } from "../../src/auth/AuthProvider";
import { Screen } from "../../src/components/Screen";

const categories = ["pothole", "garbage", "streetlight", "waterlogging", "road_damage", "encroachment", "graffiti", "open_drain", "fallen_tree", "other"];

type CapturedLocation = {
  latitude: number;
  longitude: number;
  accuracy?: number;
  address: string;
};

function label(value: string): string {
  return value.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function uploadAsset(asset: ImagePicker.ImagePickerAsset): UploadAsset {
  return { uri: asset.uri, fileName: asset.fileName, mimeType: asset.mimeType };
}

export default function ReportScreen() {
  const { ready, user, login, logout } = useAuth();
  const queryClient = useQueryClient();
  const [asset, setAsset] = useState<ImagePicker.ImagePickerAsset | null>(null);
  const [location, setLocation] = useState<CapturedLocation | null>(null);
  const [locationBusy, setLocationBusy] = useState(false);
  const [locationMessage, setLocationMessage] = useState("Location is required before submitting.");
  const [category, setCategory] = useState("other");
  const [description, setDescription] = useState("");
  const [analysis, setAnalysis] = useState<AiAnalysis | null>(null);
  const [analysisBusy, setAnalysisBusy] = useState(false);
  const [analysisError, setAnalysisError] = useState<string | null>(null);
  const [submitBusy, setSubmitBusy] = useState(false);
  const [submittedIssueId, setSubmittedIssueId] = useState<number | null>(null);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loginBusy, setLoginBusy] = useState(false);
  const [loginError, setLoginError] = useState<string | null>(null);

  useEffect(() => {
    if (asset && user && !analysis && !analysisBusy) void analyze(asset);
  }, [asset, user]);

  async function chooseImage(fromCamera: boolean): Promise<void> {
    const permission = fromCamera
      ? await ImagePicker.requestCameraPermissionsAsync()
      : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      Alert.alert("Permission needed", fromCamera ? "Allow camera access to capture the issue." : "Allow photo access to choose an issue image.");
      return;
    }

    const result = fromCamera
      ? await ImagePicker.launchCameraAsync({ mediaTypes: ["images"], quality: 0.85 })
      : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ["images"], quality: 0.85 });
    if (result.canceled) return;

    setAsset(result.assets[0]);
    setAnalysis(null);
    setAnalysisError(user ? null : "Sign in as a citizen to run the server AI analysis.");
    setSubmittedIssueId(null);
  }

  async function captureLocation(): Promise<void> {
    setLocationBusy(true);
    setLocationMessage("Requesting location permission…");
    try {
      const permission = await Location.requestForegroundPermissionsAsync();
      if (permission.status !== "granted") {
        setLocationMessage("Location permission was not granted.");
        return;
      }
      const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
      let address = "Current location detected";
      try {
        const places = await Location.reverseGeocodeAsync({ latitude: position.coords.latitude, longitude: position.coords.longitude });
        const place = places[0];
        address = [place?.street, place?.district || place?.city, place?.region].filter(Boolean).join(", ") || address;
      } catch {
        // Coordinates are sufficient for the server; reverse geocoding is only a label.
      }
      setLocation({ latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy ?? undefined, address });
      setLocationMessage(`${address} · accuracy about ${Math.round(position.coords.accuracy ?? 0)} m`);
    } catch (error) {
      setLocationMessage(error instanceof Error ? error.message : "Could not acquire your current location.");
    } finally {
      setLocationBusy(false);
    }
  }

  async function analyze(selectedAsset: ImagePicker.ImagePickerAsset): Promise<void> {
    if (!user) {
      setAnalysisError("Sign in as a citizen before analyzing an image.");
      return;
    }
    setAnalysisBusy(true);
    setAnalysisError(null);
    try {
      const result = await civicConnectApi.analyzeIssue(uploadAsset(selectedAsset), category, location?.latitude, location?.longitude);
      setAnalysis(result.data.ai);
      if (categories.includes(result.data.ai.category)) setCategory(result.data.ai.category);
    } catch (error) {
      setAnalysisError(error instanceof Error ? error.message : "The image could not be analyzed.");
    } finally {
      setAnalysisBusy(false);
    }
  }

  async function handleLogin(): Promise<void> {
    setLoginBusy(true);
    setLoginError(null);
    try {
      await login(email, password, "citizen");
      setPassword("");
    } catch (error) {
      setLoginError(error instanceof Error ? error.message : "Sign-in failed.");
    } finally {
      setLoginBusy(false);
    }
  }

  async function submitReport(): Promise<void> {
    if (!asset || !location || !user) return;
    setSubmitBusy(true);
    try {
      const result = await civicConnectApi.submitIssue(uploadAsset(asset), {
        category,
        description,
        latitude: location.latitude,
        longitude: location.longitude,
        address: location.address,
        accuracy: location.accuracy,
      });
      setSubmittedIssueId(result.data.issue_id);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["feed"] }),
        queryClient.invalidateQueries({ queryKey: ["heatmap"] }),
      ]);
    } catch (error) {
      Alert.alert("Report not saved", error instanceof Error ? error.message : "The report could not be saved.");
    } finally {
      setSubmitBusy(false);
    }
  }

  function resetReport(): void {
    setAsset(null);
    setAnalysis(null);
    setAnalysisError(null);
    setLocation(null);
    setLocationMessage("Location is required before submitting.");
    setDescription("");
    setCategory("other");
    setSubmittedIssueId(null);
  }

  if (!ready) return <Screen><ActivityIndicator size="large" color="#4f46e5" /></Screen>;

  return (
    <Screen>
      <View style={styles.heading}>
        <Text style={styles.eyebrow}>REPORT AN ISSUE</Text>
        <Text style={styles.title}>Start a native report</Text>
        <Text style={styles.subtitle}>Choose a clear image. CivicConnect sends it to the same server-side AI used by the web app, shows the result immediately, and re-checks it before saving.</Text>
      </View>

      {!user ? (
        <View style={styles.loginCard}>
          <Text style={styles.cardTitle}>Sign in to analyze and submit</Text>
          <Text style={styles.cardText}>The public feed and City Pulse are open. Reporting requires an existing citizen account so the server can keep the report tied to you.</Text>
          <TextInput value={email} onChangeText={setEmail} placeholder="Email address" autoCapitalize="none" keyboardType="email-address" style={styles.input} />
          <TextInput value={password} onChangeText={setPassword} placeholder="Password" secureTextEntry style={styles.input} />
          {loginError ? <Text style={styles.error}>{loginError}</Text> : null}
          <Pressable onPress={() => void handleLogin()} disabled={loginBusy || !email || !password} style={[styles.primary, (loginBusy || !email || !password) && styles.disabled]}>
            {loginBusy ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryText}>Sign in as citizen</Text>}
          </Pressable>
        </View>
      ) : (
        <View style={styles.sessionCard}>
          <View><Text style={styles.cardTitle}>Signed in as {user.name}</Text><Text style={styles.cardText}>{user.email} · Citizen reports are saved to your account.</Text></View>
          <Pressable onPress={() => void logout()}><Text style={styles.link}>Sign out</Text></Pressable>
        </View>
      )}

      <View style={styles.card}>
        {asset ? <Image source={{ uri: asset.uri }} style={styles.preview} /> : <View style={styles.emptyPreview}><Text style={styles.emptyText}>Choose or capture a photo to begin</Text></View>}
        <View style={styles.buttonRow}>
          <Pressable onPress={() => void chooseImage(false)} style={[styles.primary, styles.flexButton]}><Text style={styles.primaryText}>{asset ? "Choose different" : "Choose photo"}</Text></Pressable>
          <Pressable onPress={() => void chooseImage(true)} style={[styles.secondary, styles.flexButton]}><Text style={styles.secondaryText}>Use camera</Text></Pressable>
        </View>
      </View>

      {asset ? (
        <View style={styles.aiCard}>
          <View style={styles.cardHeader}><Text style={styles.aiTitle}>AI analysis</Text>{analysisBusy ? <ActivityIndicator color="#4338ca" /> : null}</View>
          {analysisBusy ? <Text style={styles.cardText}>Uploading the image to the server and asking the trained model…</Text> : null}
          {analysisError ? <Text style={styles.error}>{analysisError}</Text> : null}
          {analysis ? <>
            {analysis.annotated_image ? <Image source={{ uri: analysis.annotated_image }} style={styles.annotatedPreview} resizeMode="contain" /> : null}
            <View style={styles.aiGrid}>
              <View><Text style={styles.aiLabel}>Detected category</Text><Text style={styles.aiValue}>{label(analysis.category)}</Text></View>
              <View><Text style={styles.aiLabel}>Confidence</Text><Text style={styles.aiValue}>{Math.round(analysis.confidence * 100)}%</Text></View>
              <View><Text style={styles.aiLabel}>Severity</Text><Text style={styles.aiValue}>{analysis.severity}/5</Text></View>
            </View>
            <Text style={analysis.low_confidence ? styles.warning : styles.cardText}>{analysis.detection_count ? `${analysis.detection_count} object${analysis.detection_count === 1 ? "" : "s"} detected and highlighted.` : "No confident object was found. This is an uncertain result, not proof that the image is clear."}</Text>
          </> : null}
        </View>
      ) : null}

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Issue details</Text>
        <Text style={styles.fieldLabel}>Category</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
          {categories.map((value) => <Pressable key={value} onPress={() => { setCategory(value); if (asset && user) void analyze(asset); }} style={[styles.chip, category === value && styles.chipSelected]}><Text style={[styles.chipText, category === value && styles.chipTextSelected]}>{label(value)}</Text></Pressable>)}
        </ScrollView>
        <Text style={styles.fieldLabel}>Additional details</Text>
        <TextInput value={description} onChangeText={setDescription} multiline numberOfLines={4} placeholder="Describe what residents should know…" style={[styles.input, styles.textArea]} />
        <Text style={styles.fieldLabel}>Location</Text>
        <Pressable onPress={() => void captureLocation()} style={styles.locationButton}>
          {locationBusy ? <ActivityIndicator color="#4338ca" /> : <Text style={styles.secondaryText}>{location ? "Refresh location" : "Capture current location"}</Text>}
          <Text style={styles.locationText}>{locationMessage}</Text>
        </Pressable>
      </View>

      {submittedIssueId ? <View style={styles.success}><Text style={styles.successTitle}>Report saved</Text><Text style={styles.successText}>Issue #{submittedIssueId} is now in the community feed. Its status starts as Open and will update as staff work on it.</Text><Pressable onPress={resetReport}><Text style={styles.link}>Start another report</Text></Pressable></View> : <Pressable onPress={() => void submitReport()} disabled={!asset || !location || !analysis || submitBusy || !user} style={[styles.submit, (!asset || !location || !analysis || submitBusy || !user) && styles.disabled]}>{submitBusy ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryText}>Submit report</Text>}</Pressable>}
      <Text style={styles.note}>The .pt model stays on the FastAPI server. The final PHP endpoint re-analyzes the stored image before creating or grouping the issue.</Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  heading: { gap: 6 },
  eyebrow: { color: "#4f46e5", fontSize: 11, fontWeight: "900", letterSpacing: 1.1 },
  title: { color: "#111827", fontSize: 27, lineHeight: 32, fontWeight: "900", letterSpacing: -0.6 },
  subtitle: { color: "#687386", lineHeight: 20 },
  card: { padding: 16, borderRadius: 18, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0", gap: 12 },
  loginCard: { padding: 16, borderRadius: 18, backgroundColor: "#eef2ff", borderWidth: 1, borderColor: "#c7d2fe", gap: 10 },
  sessionCard: { padding: 16, borderRadius: 18, backgroundColor: "#ecfdf5", borderWidth: 1, borderColor: "#bbf7d0", gap: 8 },
  cardTitle: { color: "#111827", fontWeight: "900", fontSize: 16 },
  cardText: { color: "#687386", lineHeight: 19 },
  preview: { width: "100%", height: 250, borderRadius: 14, backgroundColor: "#e8eaf0" },
  emptyPreview: { height: 180, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#e8eaf0" },
  emptyText: { color: "#687386", fontWeight: "700" },
  buttonRow: { flexDirection: "row", gap: 10 },
  flexButton: { flex: 1 },
  primary: { minHeight: 48, padding: 14, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#4f46e5" },
  primaryText: { color: "#fff", fontWeight: "900", textAlign: "center" },
  secondary: { minHeight: 48, padding: 14, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#fff", borderWidth: 1, borderColor: "#c7d2fe" },
  secondaryText: { color: "#4338ca", fontWeight: "900", textAlign: "center" },
  disabled: { opacity: 0.45 },
  input: { minHeight: 48, borderRadius: 12, borderWidth: 1, borderColor: "#d9deea", backgroundColor: "#fff", paddingHorizontal: 14, color: "#111827" },
  textArea: { minHeight: 100, paddingTop: 12, textAlignVertical: "top" },
  error: { color: "#b42318", lineHeight: 19 },
  link: { color: "#4338ca", fontWeight: "900" },
  aiCard: { padding: 16, borderRadius: 18, backgroundColor: "#eef2ff", borderWidth: 1, borderColor: "#c7d2fe", gap: 10 },
  cardHeader: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  aiTitle: { color: "#3730a3", fontSize: 16, fontWeight: "900" },
  annotatedPreview: { width: "100%", height: 260, borderRadius: 12, backgroundColor: "#111827" },
  aiGrid: { flexDirection: "row", justifyContent: "space-between", gap: 8 },
  aiLabel: { color: "#6366f1", fontSize: 11, fontWeight: "800" },
  aiValue: { color: "#111827", fontSize: 16, fontWeight: "900", marginTop: 3 },
  warning: { color: "#9a3412", lineHeight: 19 },
  fieldLabel: { color: "#374151", fontWeight: "800", marginTop: 2 },
  chips: { gap: 8, paddingVertical: 2 },
  chip: { paddingVertical: 9, paddingHorizontal: 12, borderRadius: 999, borderWidth: 1, borderColor: "#d9deea", backgroundColor: "#fff" },
  chipSelected: { backgroundColor: "#4f46e5", borderColor: "#4f46e5" },
  chipText: { color: "#596273", fontSize: 12, fontWeight: "700" },
  chipTextSelected: { color: "#fff" },
  locationButton: { minHeight: 60, padding: 12, borderRadius: 12, borderWidth: 1, borderColor: "#c7d2fe", backgroundColor: "#f8faff", gap: 5 },
  locationText: { color: "#687386", fontSize: 12, lineHeight: 17 },
  submit: { minHeight: 52, padding: 16, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#4f46e5" },
  success: { padding: 16, borderRadius: 18, backgroundColor: "#ecfdf5", borderWidth: 1, borderColor: "#bbf7d0", gap: 7 },
  successTitle: { color: "#166534", fontSize: 17, fontWeight: "900" },
  successText: { color: "#166534", lineHeight: 19 },
  note: { color: "#7c2d12", padding: 14, borderRadius: 14, backgroundColor: "#fff7ed", lineHeight: 19 },
});
