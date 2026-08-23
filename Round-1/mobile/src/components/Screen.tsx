import type { PropsWithChildren } from "react";
import { RefreshControl, ScrollView, StyleSheet, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { colors } from "../theme";

export function Screen({ children, scroll = true, refreshing = false, onRefresh, backgroundColor = colors.background }: PropsWithChildren<{ scroll?: boolean; refreshing?: boolean; onRefresh?: () => void; backgroundColor?: string }>) {
  if (scroll) {
    return (
      <SafeAreaView edges={["top"]} style={[styles.safe, { backgroundColor }]}>
        <ScrollView style={{ backgroundColor }} contentContainerStyle={styles.content} refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} /> : undefined}>{children}</ScrollView>
      </SafeAreaView>
    );
  }

  return <SafeAreaView edges={["top"]} style={[styles.safe, { backgroundColor }]}><View style={styles.content}>{children}</View></SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  content: { flexGrow: 1, padding: 18, paddingBottom: 28, gap: 14 },
});
